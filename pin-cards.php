<?php
require __DIR__ . '/config.php';
require_admin();

if (!function_exists('current_user_id')) {
    function current_user_id(): int { return (int)($_SESSION['user_id'] ?? 0); }
}
if (!function_exists('post_csrf_token')) {
    function post_csrf_token(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}
if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token(): void {
        $token = $_POST['csrf_token'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(403);
            exit('Invalid request token.');
        }
    }
}
if (!function_exists('user_can_access_event')) {
    function user_can_access_event(PDO $pdo, int $userId, int $eventId): bool {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM events e
             LEFT JOIN event_leaders el ON el.event_id = e.id
             WHERE e.id = ? AND (e.organiser_id = ? OR el.user_id = ?)'
        );
        $stmt->execute([$eventId, $userId, $userId]);
        return (bool)$stmt->fetchColumn();
    }
}
if (!function_exists('generate_team_pin')) {
    function generate_team_pin(int $length = 4): string {
        $pin = '';
        for ($i = 0; $i < $length; $i++) {
            $pin .= (string) random_int(0, 9);
        }
        return $pin;
    }
}
if (!function_exists('normalise_pin')) {
    function normalise_pin(string $pin): string {
        return preg_replace('/\D+/', '', $pin) ?? '';
    }
}

$userId = current_user_id();
$eventId = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM teams LIKE 'team_pin_hash'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE teams ADD COLUMN team_pin_hash VARCHAR(255) NULL AFTER color");
    }
} catch (Throwable $e) {
    http_response_code(500);
    exit('Could not prepare team PIN storage.');
}

if ($eventId < 1 || !user_can_access_event($pdo, $userId, $eventId)) {
    http_response_code(403);
    exit('Event not found or not allowed.');
}

$stmt = $pdo->prepare('SELECT id, event_name FROM events WHERE id = ? LIMIT 1');
$stmt->execute([$eventId]);
$event = $stmt->fetch();
if (!$event) {
    http_response_code(404);
    exit('Event not found.');
}

$stmt = $pdo->prepare('SELECT id, team_name, color FROM teams WHERE event_id = ? ORDER BY team_name');
$stmt->execute([$eventId]);
$teams = $stmt->fetchAll();

$printedPins = [];
$error = '';
$csrf = post_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_cards') {
    verify_csrf_token();

    if (!$teams) {
        $error = 'No teams found for this event.';
    } else {
        foreach ($teams as $team) {
            $field = 'pin_' . (int)$team['id'];
            $pin = normalise_pin((string)($_POST[$field] ?? ''));
            if ($pin === '') {
                $pin = generate_team_pin();
            }

            $stmt = $pdo->prepare('UPDATE teams SET team_pin_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($pin, PASSWORD_DEFAULT), (int)$team['id']]);

            $printedPins[] = [
                'team_name' => $team['team_name'],
                'color' => $team['color'] ?: '#7413DC',
                'pin' => $pin,
            ];
        }
    }
}

$host = $_SERVER['HTTP_HOST'] ?? 'gridtracking.com';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
$publicUrl = $scheme . '://' . $host . '/index.html';
$qrPath = 'gridtracking-qr.png';
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Printable PIN cards – <?= h($event['event_name']) ?></title>
    <link rel="icon" href="favicon.ico">
    <style>
        body {font-family:system-ui; background:#f4f4f4; margin:0; padding:20px; color:#222;}
        .wrap {max-width:1100px; margin:0 auto;}
        .controls {background:#fff; border-radius:16px; padding:24px; box-shadow:0 6px 20px rgba(0,0,0,0.08); margin-bottom:18px;}
        h1 {margin-top:0; color:#2E7D32;}
        .btn {display:inline-block; border:none; border-radius:10px; padding:11px 16px; cursor:pointer; text-decoration:none; font-size:0.98rem;}
        .btn-primary {background:#1565C0; color:#fff;}
        .btn-secondary {background:#f3f3f3; color:#222; border:1px solid #ccc;}
        input[type="text"] {width:100%; padding:10px; border:2px solid #ddd; border-radius:10px; box-sizing:border-box;}
        .grid {display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px;}
        .notice {padding:12px 14px; border-radius:10px; margin:14px 0;}
        .notice.error {background:#fdeaea; color:#8a1f1f;}
        .cards {display:grid; grid-template-columns:repeat(2, 1fr); gap:16px;}
        .card {background:#fff; border:2px dashed #bbb; border-radius:16px; padding:18px; page-break-inside:avoid; min-height:260px;}
        .team {font-size:1.45rem; font-weight:800; margin-bottom:10px;}
        .pin {font-size:2.4rem; font-weight:900; letter-spacing:0.14em; margin:16px 0; padding:12px 16px; background:#f8f8f8; border-radius:12px; text-align:center;}
        .chip {display:inline-flex; align-items:center; gap:8px; font-size:0.95rem; background:#f8f8f8; border-radius:999px; padding:6px 10px;}
        .dot {width:14px; height:14px; border-radius:50%; display:inline-block; border:1px solid rgba(0,0,0,0.15);}
        .small {font-size:0.95rem; color:#555;}
        .url {font-weight:700; word-break:break-all;}
        .qr {display:block; width:110px; height:110px; margin:12px 0;}
        .steps {margin:12px 0 0 18px; padding:0;}
        .steps li {margin-bottom:4px;}
        @media print {
            body {background:#fff; padding:0;}
            .no-print {display:none !important;}
            .cards {gap:12px;}
            .card {box-shadow:none; border:1px solid #999;}
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="controls no-print">
        <h1>Printable PIN cards</h1>
        <p><a href="events.php" class="btn btn-secondary">← Back to events</a></p>
        <p>Create fresh PIN cards for <strong><?= h($event['event_name']) ?></strong>. Existing PINs cannot be shown because they are stored only as hashes. This page will set new PINs and then show printable cards.</p>

        <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="generate_cards">
            <input type="hidden" name="event_id" value="<?= $eventId ?>">

            <div class="grid">
                <?php foreach ($teams as $team): ?>
                    <label>
                        <strong><?= h($team['team_name']) ?></strong>
                        <input type="text" name="pin_<?= (int)$team['id'] ?>" inputmode="numeric" pattern="[0-9]*" maxlength="8" placeholder="Leave blank to auto-generate">
                    </label>
                <?php endforeach; ?>
            </div>

            <p style="margin-top:14px;">
                <button class="btn btn-primary" type="submit">Generate cards</button>
                <button class="btn btn-secondary" type="button" onclick="window.print()">Print current view</button>
            </p>
        </form>
    </div>

    <?php if ($printedPins): ?>
        <div class="cards">
            <?php foreach ($printedPins as $row): ?>
                <div class="card">
                    <div class="chip"><span class="dot" style="background:<?= h($row['color']) ?>"></span> Grid Tracking</div>
                    <div class="team"><?= h($row['team_name']) ?></div>
                    <img src="<?= h($qrPath) ?>" alt="QR code for Grid Tracking" class="qr">
                    <div class="url"><?= h($publicUrl) ?></div>
                    <p class="small" style="margin-top:10px;">Team PIN:</p>
                    <div class="pin"><?= h($row['pin']) ?></div>
                    <ol class="small steps">
                        <li>Scan the QR code or open the web address above.</li>
                        <li>Select your team.</li>
                        <li>Enter the PIN shown here.</li>
                        <li>Allow location access and tap <strong>Share My Location Now</strong>.</li>
                    </ol>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>