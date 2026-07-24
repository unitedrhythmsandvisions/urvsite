<?php
declare(strict_types=1);

header('X-Robots-Tag: noindex, nofollow, noarchive', true);
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, max-age=0');

const SITE_TIMEZONE = 'America/New_York';
$eventsFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'events.json';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function shortened(string $value, int $limit): string
{
    $value = trim($value);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $limit);
    }
    return substr($value, 0, $limit);
}

function loadEvents(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $json = file_get_contents($path);
    if ($json === false || trim($json) === '') {
        return [];
    }

    $events = json_decode($json, true);
    return is_array($events) ? array_values($events) : [];
}

function saveEvents(string $path, array $events): void
{
    usort($events, static fn(array $a, array $b): int => strcmp((string)($a['start'] ?? ''), (string)($b['start'] ?? '')));

    $json = json_encode(array_values($events), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Could not prepare the event data.');
    }

    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('The event file is not writable.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Could not lock the event file.');
        }
        ftruncate($handle, 0);
        rewind($handle);
        if (fwrite($handle, $json . PHP_EOL) === false) {
            throw new RuntimeException('Could not save the event file.');
        }
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

function validWebUrl(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $url = filter_var($value, FILTER_VALIDATE_URL);
    if ($url === false) {
        return '';
    }

    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) ? $url : '';
}

function localDateTime(string $value): ?DateTimeImmutable
{
    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone(SITE_TIMEZONE));
    } catch (Throwable) {
        return null;
    }
}

$events = loadEvents($eventsFile);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'delete') {
            $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['id'] ?? ''));
            $events = array_values(array_filter(
                $events,
                static fn(array $event): bool => (string)($event['id'] ?? '') !== $id
            ));
            saveEvents($eventsFile, $events);
            header('Location: ./?deleted=1');
            exit;
        }

        if ($action === 'save') {
            $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['id'] ?? ''));
            if ($id === '') {
                $id = bin2hex(random_bytes(8));
            }

            $title = shortened((string)($_POST['title'] ?? ''), 120);
            $date = trim((string)($_POST['date'] ?? ''));
            $startTime = trim((string)($_POST['start_time'] ?? ''));
            $endTime = trim((string)($_POST['end_time'] ?? ''));

            if ($title === '') {
                $title = 'Live Performance';
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $startTime)) {
                throw new RuntimeException('Choose an event date and start time.');
            }

            $timezone = new DateTimeZone(SITE_TIMEZONE);
            $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $startTime, $timezone);
            if (!$start) {
                throw new RuntimeException('The event date or start time is invalid.');
            }

            if ($endTime !== '' && preg_match('/^\d{2}:\d{2}$/', $endTime)) {
                $end = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $endTime, $timezone);
                if (!$end) {
                    throw new RuntimeException('The ending time is invalid.');
                }
                if ($end <= $start) {
                    $end = $end->modify('+1 day');
                }
            } else {
                $end = $start->modify('+3 hours');
            }

            $event = [
                'id' => $id,
                'title' => $title,
                'start' => $start->format(DateTimeInterface::ATOM),
                'end' => $end->format(DateTimeInterface::ATOM),
                'location' => shortened((string)($_POST['location'] ?? ''), 160),
                'address' => shortened((string)($_POST['address'] ?? ''), 220),
                'details' => shortened((string)($_POST['details'] ?? ''), 1200),
                'link' => validWebUrl((string)($_POST['link'] ?? '')),
            ];

            $replaced = false;
            foreach ($events as $index => $existing) {
                if ((string)($existing['id'] ?? '') === $id) {
                    $events[$index] = $event;
                    $replaced = true;
                    break;
                }
            }
            if (!$replaced) {
                $events[] = $event;
            }

            saveEvents($eventsFile, $events);
            header('Location: ./?saved=1');
            exit;
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$events = loadEvents($eventsFile);
usort($events, static fn(array $a, array $b): int => strcmp((string)($a['start'] ?? ''), (string)($b['start'] ?? '')));

$editId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['edit'] ?? ''));
$editing = null;
foreach ($events as $event) {
    if ((string)($event['id'] ?? '') === $editId) {
        $editing = $event;
        break;
    }
}

$formStart = $editing ? localDateTime((string)($editing['start'] ?? '')) : null;
$formEnd = $editing ? localDateTime((string)($editing['end'] ?? '')) : null;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>Website Events</title>
<style>
:root{color-scheme:dark;--bg:#0d0c12;--panel:#191721;--line:#393442;--text:#fff;--muted:#c8c2ce;--gold:#ffd058;--green:#55d989;--red:#ff7070}
*{box-sizing:border-box}
body{margin:0;background:linear-gradient(180deg,#19110a,#0d0c12 45%);color:var(--text);font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}
main{width:min(900px,94vw);margin:0 auto;padding:32px 0 70px}
h1{margin:0;font-size:clamp(32px,7vw,58px);line-height:1}
.intro{margin:12px 0 26px;color:var(--muted);font-size:18px;line-height:1.5}
.notice,.error{padding:16px 18px;border-radius:16px;margin:0 0 20px;font-weight:850}
.notice{background:rgba(85,217,137,.14);border:1px solid rgba(85,217,137,.45)}
.error{background:rgba(255,112,112,.14);border:1px solid rgba(255,112,112,.45)}
.panel,.event{background:rgba(25,23,33,.95);border:1px solid var(--line);border-radius:24px;padding:22px;box-shadow:0 16px 38px rgba(0,0,0,.28)}
.panel{margin-bottom:34px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.full{grid-column:1/-1}
label{display:block;font-size:15px;font-weight:900;margin-bottom:7px}
small{display:block;color:var(--muted);font-weight:500;margin-top:5px}
input,textarea{width:100%;border-radius:13px;border:2px solid #50495c;background:#0d0c12;color:#fff;padding:14px;font:inherit;font-size:18px}
input:focus,textarea:focus{outline:3px solid rgba(255,208,88,.35);border-color:var(--gold)}
textarea{min-height:110px;resize:vertical}
.actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:20px}
button,.button{appearance:none;border:0;border-radius:14px;padding:15px 22px;font:inherit;font-size:18px;font-weight:950;cursor:pointer;text-decoration:none;text-align:center}
.save{background:var(--green);color:#07130b;min-width:220px}
.cancel,.edit{background:#ddd5e3;color:#17121a}
.delete{background:var(--red);color:#270606}
h2{font-size:30px;margin:0 0 16px}
.events{display:grid;gap:16px}
.event{display:grid;grid-template-columns:1fr auto;gap:18px;align-items:center}
.event h3{margin:0 0 8px;font-size:24px}
.event p{margin:4px 0;color:var(--muted);line-height:1.45}
.event strong{color:#fff}
.event-buttons{display:grid;gap:10px;min-width:130px}
.empty{padding:24px;border:2px dashed var(--line);border-radius:20px;color:var(--muted);font-size:18px;text-align:center}
.top-link{display:inline-block;margin-bottom:22px;color:#cfe8ff}
@media(max-width:650px){.grid{grid-template-columns:1fr}.full{grid-column:auto}.event{grid-template-columns:1fr}.event-buttons{display:flex;min-width:0}.event-buttons>*{flex:1}.save{width:100%}}
</style>
</head>
<body>
<main>
<a class="top-link" href="../" target="_blank" rel="noopener">Open the public website</a>
<h1>Website Events</h1>
<p class="intro">Add a show here and press the big green button. Past shows disappear from the public website automatically.</p>

<?php if (isset($_GET['saved'])): ?><div class="notice">Event published.</div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="notice">Event deleted.</div><?php endif; ?>
<?php if ($error !== ''): ?><div class="error"><?= h($error) ?></div><?php endif; ?>

<section class="panel" id="event-form">
<h2><?= $editing ? 'Edit Event' : 'Add an Event' ?></h2>
<form method="post" action="./#event-form">
<input type="hidden" name="action" value="save">
<input type="hidden" name="id" value="<?= h((string)($editing['id'] ?? '')) ?>">
<div class="grid">
<div class="full">
<label for="title">Event name</label>
<input id="title" name="title" maxlength="120" value="<?= h((string)($editing['title'] ?? '')) ?>" placeholder="Rhythms of the Hearts Live" autofocus>
</div>
<div>
<label for="date">Date</label>
<input id="date" name="date" type="date" required value="<?= h($formStart?->format('Y-m-d') ?? '') ?>">
</div>
<div>
<label for="start_time">Start time</label>
<input id="start_time" name="start_time" type="time" required value="<?= h($formStart?->format('H:i') ?? '') ?>">
</div>
<div>
<label for="end_time">End time <small>Leave blank and it will use three hours.</small></label>
<input id="end_time" name="end_time" type="time" value="<?= h($formEnd?->format('H:i') ?? '') ?>">
</div>
<div>
<label for="location">Venue</label>
<input id="location" name="location" maxlength="160" value="<?= h((string)($editing['location'] ?? '')) ?>" placeholder="State Theatre New Jersey">
</div>
<div class="full">
<label for="address">Address</label>
<input id="address" name="address" maxlength="220" value="<?= h((string)($editing['address'] ?? '')) ?>" placeholder="15 Livingston Avenue, New Brunswick, NJ">
</div>
<div class="full">
<label for="details">Extra details</label>
<textarea id="details" name="details" maxlength="1200" placeholder="Anything visitors should know."><?= h((string)($editing['details'] ?? '')) ?></textarea>
</div>
<div class="full">
<label for="link">Ticket or information link</label>
<input id="link" name="link" type="url" value="<?= h((string)($editing['link'] ?? '')) ?>" placeholder="https://...">
</div>
</div>
<div class="actions">
<button class="save" type="submit"><?= $editing ? 'SAVE CHANGES' : 'PUBLISH EVENT' ?></button>
<?php if ($editing): ?><a class="button cancel" href="./">CANCEL EDITING</a><?php endif; ?>
</div>
</form>
</section>

<h2>Current and Future Events</h2>
<div class="events">
<?php
$visibleCount = 0;
$now = new DateTimeImmutable('now', new DateTimeZone(SITE_TIMEZONE));
foreach ($events as $event):
    $start = localDateTime((string)($event['start'] ?? ''));
    $end = localDateTime((string)($event['end'] ?? ''));
    if (!$start || !$end || $end <= $now) {
        continue;
    }
    $visibleCount++;
?>
<article class="event">
<div>
<h3><?= h((string)($event['title'] ?? 'Live Performance')) ?></h3>
<p><strong><?= h($start->format('l, F j, Y')) ?> at <?= h($start->format('g:i A')) ?></strong></p>
<?php if (!empty($event['location'])): ?><p><?= h((string)$event['location']) ?></p><?php endif; ?>
<?php if (!empty($event['address'])): ?><p><?= h((string)$event['address']) ?></p><?php endif; ?>
</div>
<div class="event-buttons">
<a class="button edit" href="./?edit=<?= rawurlencode((string)$event['id']) ?>#event-form">EDIT</a>
<form method="post" action="./" onsubmit="return confirm('Delete this event from the website?');">
<input type="hidden" name="action" value="delete">
<input type="hidden" name="id" value="<?= h((string)$event['id']) ?>">
<button class="delete" type="submit">DELETE</button>
</form>
</div>
</article>
<?php endforeach; ?>
<?php if ($visibleCount === 0): ?><div class="empty">No upcoming events are published.</div><?php endif; ?>
</div>
</main>
</body>
</html>
