<?php
require __DIR__ . '/config.php';
require_admin();

function ensure_event_settings_columns_page(PDO $pdo): void {
    $columns = [
        'rate_limit_minutes' => "ALTER TABLE events ADD COLUMN rate_limit_minutes INT NOT NULL DEFAULT 5",
        'reminder_minutes' => "ALTER TABLE events ADD COLUMN reminder_minutes INT NOT NULL DEFAULT 10",
        'stale_minutes' => "ALTER TABLE events ADD COLUMN stale_minutes INT NOT NULL DEFAULT 10",
    ];

    foreach ($columns as $column => $sql) {
        $stmt = $pdo->query("SHOW COLUMNS FROM events LIKE " . $pdo->quote($column));
        if (!$stmt->fetch()) {
            $pdo->exec($sql);
        }
    }
}

ensure_event_settings_columns_page($pdo);

$userId = current_user_id();
$event = get_active_event($pdo, $userId);

$message = '';
$error = '';

if (!$event) {
    $error = 'No active event found. Activate an event first.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $event) {
    verify_csrf_token();

    $rateLimit = max(1, min(60, (int)($_POST['rate_limit_minutes'] ?? 5)));
    $reminder = max(1, min(180, (int)($_POST['reminder_minutes'] ?? 10)));
    $stale = max(1, min(180, (int)($_POST['stale_minutes'] ?? 10)));

    $stmt = $pdo->prepare(
        'UPDATE events
         SET rate_limit_minutes = ?, reminder_minutes = ?, stale_minutes = ?
         WHERE id = ?'
    );
    $stmt->execute([$rateLimit, $reminder, $stale, (int)$event['id']]);

    $message = 'Event settings saved.';

    $event = get_active_event($pdo, $userId);
}

$csrf = post_csrf_token();

$rateLimit = (int)($event['rate_limit_minutes'] ?? 5);
$reminder = (int)($event['reminder_minutes'] ?? 10);
$stale = (int)($event['stale_minutes'] ?? 10);
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Settings – Grid Tracking</title>
    <link rel="icon" href="favicon.ico">
    <style>
        body { font-family: system-ui, sans-serif; background:#f4f4f4; margin:0; padding:20px; color:#222; }
        .container { max-width:680px; margin:0 auto; background:#fff; border-radius:16px; padding:28px; box-shadow:0 6px 20px rgba(0,0,0,0.1); }
        h1 { color:#2E7D32; margin-top:0; }
        label { display:block; font-weight:700; margin-top:16px; }
        input { width:100%; padding:12px; margin-top:6px; border:2px solid #ddd; border-radius:10px; font-size:1rem; box-sizing:border-box; }
        .hint { color:#666; font-size:0.92rem; margin:6px 0 0; }
        .notice { padding:12px 14px; border-radius:10px; margin:14px 0; }
        .ok { background:#edf7ed; color:#216e39; }
        .error { background:#fdeaea; color:#8a1f1f; }
        .btn { display:inline-block; border:none; border-radius:10px; padding:12px 16px; margin-top:18px; cursor:pointer; text-decoration:none; font-size:1rem; }
        .primary { background:#1565C0; color:#fff; }
        .secondary { background:#f3f3f3; color:#222; border:1px solid #ccc; }
    </style>
</head>
<body>
<div class="container">
    <h1>Event Settings</h1>
    <p><a class="btn secondary" href="admin.php">← Back to control panel</a></p>

    <?php if ($message): ?><div class="notice ok"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

    <?php if ($event): ?>
        <p><strong>Active event:</strong> <?= h($event['event_name']) ?></p>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

            <label for="rate_limit_minutes">Check-in cooldown, in minutes</label>
            <input id="rate_limit_minutes" name="rate_limit_minutes" type="number" min="1" max="60" value="<?= h((string)$rateLimit) ?>">
            <p class="hint">How soon a team can submit another location after a successful check-in.</p>

            <label for="reminder_minutes">Reminder after, in minutes</label>
            <input id="reminder_minutes" name="reminder_minutes" type="number" min="1" max="180" value="<?= h((string)$reminder) ?>">
            <p class="hint">Used by the in-app reminder while the app is open.</p>

            <label for="stale_minutes">Mark team stale after, in minutes</label>
            <input id="stale_minutes" name="stale_minutes" type="number" min="1" max="180" value="<?= h((string)$stale) ?>">
            <p class="hint">Used by the admin dashboard to highlight teams that need attention.</p>

            <button class="btn primary" type="submit">Save settings</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
