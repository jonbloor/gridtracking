<?php
require __DIR__ . '/config.php';
require_admin();

if (!function_exists('current_user_id')) {
    function current_user_id(): int {
        return (int)($_SESSION['user_id'] ?? 0);
    }
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
if (!function_exists('get_platform_on')) {
    function get_platform_on(PDO $pdo): bool {
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE name = 'platform_on' LIMIT 1");
        $stmt->execute();
        return (string)$stmt->fetchColumn() === '1';
    }
}
if (!function_exists('set_platform_on')) {
    function set_platform_on(PDO $pdo, bool $value): void {
        $stmt = $pdo->prepare("INSERT INTO settings (name, value) VALUES ('platform_on', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
        $stmt->execute([$value ? 1 : 0]);
    }
}
if (!function_exists('user_owns_event')) {
    function user_owns_event(PDO $pdo, int $userId, int $eventId): bool {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM events WHERE id = ? AND organiser_id = ?');
        $stmt->execute([$eventId, $userId]);
        return (bool)$stmt->fetchColumn();
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
if (!function_exists('team_colour_palette')) {
    function team_colour_palette(): array {
        return [
            '#D7263D', '#F49D37', '#FFD23F', '#2E86AB',
            '#6C5CE7', '#00A676', '#FF6F91', '#3A3A3A',
            '#1B9AAA', '#F26419', '#8E5572', '#33658A',
        ];
    }
}
if (!function_exists('next_team_colour')) {
    function next_team_colour(PDO $pdo, int $eventId, string $seed = ''): string {
        $palette = team_colour_palette();
        $stmt = $pdo->prepare('SELECT color FROM teams WHERE event_id = ?');
        $stmt->execute([$eventId]);
        $used = array_filter(array_map(static fn($r) => strtoupper((string)($r['color'] ?? '')), $stmt->fetchAll()));

        foreach ($palette as $colour) {
            if (!in_array(strtoupper($colour), $used, true)) {
                return $colour;
            }
        }

        $hash = abs(crc32(mb_strtolower(trim($seed))));
        return $palette[$hash % count($palette)];
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
if (!function_exists('delete_event_locations')) {
    function delete_event_locations(PDO $pdo, int $eventId): int {
        $stmt = $pdo->prepare('DELETE FROM locations WHERE event_id = ?');
        $stmt->execute([$eventId]);
        return $stmt->rowCount();
    }
}
if (!function_exists('create_user_account')) {
    function create_user_account(PDO $pdo, string $username, string $password, string $fullName = '', bool $isOrganiser = false): int {
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, password_hash, full_name, is_organiser)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $username,
            password_hash($password, PASSWORD_DEFAULT),
            mb_substr($fullName, 0, 100),
            $isOrganiser ? 1 : 0,
        ]);
        return (int)$pdo->lastInsertId();
    }
}

$userId = current_user_id();
$message = '';
$error = '';
$generatedPins = $_SESSION['generated_pins'] ?? [];
unset($_SESSION['generated_pins']);

function ensure_team_pin_column(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    $stmt = $pdo->query("SHOW COLUMNS FROM teams LIKE 'team_pin_hash'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE teams ADD COLUMN team_pin_hash VARCHAR(255) NULL AFTER color");
    }
}
ensure_team_pin_column($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_event') {
        $eventName = trim((string)($_POST['event_name'] ?? ''));
        if ($eventName === '') {
            $error = 'Please enter an event name.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO events (organiser_id, event_name, is_active) VALUES (?, ?, 0)');
            $stmt->execute([$userId, mb_substr($eventName, 0, 100)]);
            header('Location: events.php?created=1');
            exit;
        }
    }

    if ($action === 'add_team') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        $teamName = trim((string)($_POST['team_name'] ?? ''));
        $colourInput = strtoupper(trim((string)($_POST['color'] ?? '')));
        $pinInput = normalise_pin((string)($_POST['team_pin'] ?? ''));

        if ($eventId < 1 || $teamName === '') {
            $error = 'Please choose an event and enter a team name.';
        } elseif (!user_can_access_event($pdo, $userId, $eventId)) {
            $error = 'You do not have access to that event.';
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM teams WHERE event_id = ? AND team_name = ?');
            $stmt->execute([$eventId, $teamName]);
            if ((int)$stmt->fetchColumn() > 0) {
                $error = 'That team already exists for this event.';
            } else {
                $colour = preg_match('/^#[0-9A-F]{6}$/', $colourInput)
                    ? $colourInput
                    : next_team_colour($pdo, $eventId, $teamName);
                $pin = $pinInput !== '' ? $pinInput : generate_team_pin();
                $stmt = $pdo->prepare('INSERT INTO teams (event_id, team_name, color, team_pin_hash) VALUES (?, ?, ?, ?)');
                $stmt->execute([$eventId, mb_substr($teamName, 0, 100), $colour, password_hash($pin, PASSWORD_DEFAULT)]);
                $_SESSION['generated_pins'] = [['team' => $teamName, 'pin' => $pin]];
                header('Location: events.php?team_added=1');
                exit;
            }
        }
    }

    if ($action === 'add_teams_bulk') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        $bulkTeams = trim((string)($_POST['team_names'] ?? ''));

        if ($eventId < 1 || $bulkTeams === '') {
            $error = 'Please choose an event and enter one or more team names.';
        } elseif (!user_can_access_event($pdo, $userId, $eventId)) {
            $error = 'You do not have access to that event.';
        } else {
            $lines = preg_split('/\r\n|\r|\n/', $bulkTeams) ?: [];
            $names = [];
            foreach ($lines as $line) {
                $name = trim($line);
                if ($name !== '') {
                    $names[] = mb_substr($name, 0, 100);
                }
            }

            if (!$names) {
                $error = 'Please enter at least one valid team name.';
            } else {
                $added = 0;
                $pinList = [];
                foreach ($names as $teamName) {
                    $stmt = $pdo->prepare('SELECT COUNT(*) FROM teams WHERE event_id = ? AND team_name = ?');
                    $stmt->execute([$eventId, $teamName]);
                    if ((int)$stmt->fetchColumn() > 0) {
                        continue;
                    }

                    $colour = next_team_colour($pdo, $eventId, $teamName);
                    $pin = generate_team_pin();
                    $stmt = $pdo->prepare('INSERT INTO teams (event_id, team_name, color, team_pin_hash) VALUES (?, ?, ?, ?)');
                    $stmt->execute([$eventId, $teamName, $colour, password_hash($pin, PASSWORD_DEFAULT)]);
                    $added++;
                    $pinList[] = ['team' => $teamName, 'pin' => $pin];
                }

                $_SESSION['generated_pins'] = $pinList;
                header('Location: events.php?bulk_added=' . $added);
                exit;
            }
        }
    }

    if ($action === 'reset_team_pin') {
        $teamId = (int)($_POST['team_id'] ?? 0);
        $pinInput = normalise_pin((string)($_POST['team_pin'] ?? ''));

        $stmt = $pdo->prepare(
            'SELECT t.id, t.team_name
             FROM teams t
             INNER JOIN events e ON e.id = t.event_id
             LEFT JOIN event_leaders el ON el.event_id = e.id
             WHERE t.id = ? AND (e.organiser_id = ? OR el.user_id = ?)
             LIMIT 1'
        );
        $stmt->execute([$teamId, $userId, $userId]);
        $team = $stmt->fetch();

        if (!$team) {
            $error = 'Team not found or not allowed.';
        } else {
            $pin = $pinInput !== '' ? $pinInput : generate_team_pin();
            $stmt = $pdo->prepare('UPDATE teams SET team_pin_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($pin, PASSWORD_DEFAULT), $teamId]);
            $_SESSION['generated_pins'] = [['team' => $team['team_name'], 'pin' => $pin]];
            header('Location: events.php?pin_reset=1');
            exit;
        }
    }

    if ($action === 'delete_team') {
        $teamId = (int)($_POST['team_id'] ?? 0);

        $stmt = $pdo->prepare(
            'SELECT t.id, t.event_id
             FROM teams t
             INNER JOIN events e ON e.id = t.event_id
             LEFT JOIN event_leaders el ON el.event_id = e.id
             WHERE t.id = ? AND (e.organiser_id = ? OR el.user_id = ?)
             LIMIT 1'
        );
        $stmt->execute([$teamId, $userId, $userId]);
        $team = $stmt->fetch();

        if ($team) {
            $stmt = $pdo->prepare('DELETE FROM teams WHERE id = ?');
            $stmt->execute([$teamId]);
            header('Location: events.php?team_deleted=1');
            exit;
        }

        $error = 'Team not found or not allowed.';
    }

    if ($action === 'delete_event') {
        $eventId = (int)($_POST['event_id'] ?? 0);

        if (!user_owns_event($pdo, $userId, $eventId)) {
            $error = 'Only the organiser can delete that event.';
        } else {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('UPDATE events SET is_active = 0 WHERE id = ? AND organiser_id = ?');
                $stmt->execute([$eventId, $userId]);

                $stmt = $pdo->prepare('DELETE FROM event_leaders WHERE event_id = ?');
                $stmt->execute([$eventId]);

                $stmt = $pdo->prepare('DELETE FROM locations WHERE event_id = ?');
                $stmt->execute([$eventId]);

                $stmt = $pdo->prepare('DELETE FROM teams WHERE event_id = ?');
                $stmt->execute([$eventId]);

                $stmt = $pdo->prepare('DELETE FROM events WHERE id = ? AND organiser_id = ?');
                $stmt->execute([$eventId, $userId]);

                $pdo->commit();
                header('Location: events.php?event_deleted=1');
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = 'Could not delete that event.';
            }
        }
    }

    if ($action === 'activate_event') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        if (!user_owns_event($pdo, $userId, $eventId)) {
            $error = 'Only the organiser can activate that event.';
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->exec('UPDATE events SET is_active = 0');
                $stmt = $pdo->prepare('UPDATE events SET is_active = 1 WHERE id = ? AND organiser_id = ?');
                $stmt->execute([$eventId, $userId]);
                set_platform_on($pdo, true);
                $pdo->commit();
                header('Location: events.php?activated=1');
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = 'Could not activate the event.';
            }
        }
    }

    if ($action === 'deactivate_event') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        $deleteLogs = (int)($_POST['delete_logs'] ?? 0) === 1;

        if (!user_owns_event($pdo, $userId, $eventId)) {
            $error = 'Only the organiser can turn that event off.';
        } else {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('UPDATE events SET is_active = 0 WHERE id = ? AND organiser_id = ?');
                $stmt->execute([$eventId, $userId]);
                if ($deleteLogs) {
                    $deleted = delete_event_locations($pdo, $eventId);
                    $message = 'Event turned off and ' . $deleted . ' logged location(s) deleted.';
                } else {
                    $message = 'Event turned off.';
                }
                $pdo->commit();
                header('Location: events.php?deactivated=1' . ($deleteLogs ? '&deleted_logs=1' : ''));
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = 'Could not turn that event off.';
            }
        }
    }

    if ($action === 'set_platform') {
        $platformOn = (int)($_POST['platform_on'] ?? 0) === 1;
        $deleteLogs = (int)($_POST['delete_logs'] ?? 0) === 1;
        $activeEventId = (int)($_POST['active_event_id'] ?? 0);

        $pdo->beginTransaction();
        try {
            set_platform_on($pdo, $platformOn);
            if (!$platformOn && $activeEventId > 0 && $deleteLogs && user_owns_event($pdo, $userId, $activeEventId)) {
                delete_event_locations($pdo, $activeEventId);
            }
            $pdo->commit();
            header('Location: events.php?platform=' . ($platformOn ? 'on' : 'off') . ($deleteLogs ? '&deleted_logs=1' : ''));
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = 'Could not update platform status.';
        }
    }

    if ($action === 'add_existing_leader') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        $username = trim((string)($_POST['leader_username'] ?? ''));

        if (!user_owns_event($pdo, $userId, $eventId)) {
            $error = 'Only the organiser can manage extra leaders.';
        } elseif ($username === '') {
            $error = 'Please enter a leader username.';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            $leader = $stmt->fetch();

            if (!$leader) {
                $error = 'No user account exists with that username yet.';
            } else {
                $leaderId = (int)$leader['id'];
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM event_leaders WHERE event_id = ? AND user_id = ?');
                $stmt->execute([$eventId, $leaderId]);

                if ((int)$stmt->fetchColumn() === 0) {
                    $stmt = $pdo->prepare('INSERT INTO event_leaders (event_id, user_id) VALUES (?, ?)');
                    $stmt->execute([$eventId, $leaderId]);
                }

                header('Location: events.php?leader_added=1');
                exit;
            }
        }
    }

    if ($action === 'create_and_add_leader') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        $fullName = trim((string)($_POST['leader_name'] ?? ''));
        $username = trim((string)($_POST['leader_username_new'] ?? ''));
        $password = (string)($_POST['leader_password'] ?? '');

        if (!user_owns_event($pdo, $userId, $eventId)) {
            $error = 'Only the organiser can create extra leader logins.';
        } elseif ($username === '') {
            $error = 'Please enter a username for the leader.';
        } elseif (strlen($password) < 10) {
            $error = 'Please use a password of at least 10 characters for the leader account.';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            $existing = $stmt->fetch();
            if ($existing) {
                $error = 'That username already has an account. Use the existing leader form instead.';
            } else {
                $pdo->beginTransaction();
                try {
                    $leaderId = create_user_account($pdo, $username, $password, $fullName, false);
                    $stmt = $pdo->prepare('INSERT INTO event_leaders (event_id, user_id) VALUES (?, ?)');
                    $stmt->execute([$eventId, $leaderId]);
                    $pdo->commit();
                    header('Location: events.php?leader_created=1');
                    exit;
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $error = 'Could not create that leader account.';
                }
            }
        }
    }

    if ($action === 'remove_leader') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        $leaderId = (int)($_POST['leader_id'] ?? 0);

        if (!user_owns_event($pdo, $userId, $eventId)) {
            $error = 'Only the organiser can remove extra leaders.';
        } else {
            $stmt = $pdo->prepare('DELETE FROM event_leaders WHERE event_id = ? AND user_id = ?');
            $stmt->execute([$eventId, $leaderId]);
            header('Location: events.php?leader_removed=1');
            exit;
        }
    }
}

if (isset($_GET['created'])) {
    $message = 'Event created.';
} elseif (isset($_GET['team_added'])) {
    $message = 'Team added.';
} elseif (isset($_GET['team_deleted'])) {
    $message = 'Team deleted.';
} elseif (isset($_GET['event_deleted'])) {
    $message = 'Event deleted.';
} elseif (isset($_GET['activated'])) {
    $message = 'Event activated.';
} elseif (isset($_GET['deactivated'])) {
    $message = isset($_GET['deleted_logs']) ? 'Event turned off and logs deleted.' : 'Event turned off.';
} elseif (isset($_GET['pin_reset'])) {
    $message = 'Team PIN reset.';
} elseif (isset($_GET['leader_added'])) {
    $message = 'Existing leader added to the event.';
} elseif (isset($_GET['leader_created'])) {
    $message = 'Leader account created and added to the event.';
} elseif (isset($_GET['leader_removed'])) {
    $message = 'Leader removed from the event.';
} elseif (isset($_GET['bulk_added'])) {
    $message = (int)$_GET['bulk_added'] . ' teams added.';
} elseif (($_GET['platform'] ?? '') === 'on') {
    $message = 'Platform turned on.';
} elseif (($_GET['platform'] ?? '') === 'off') {
    $message = isset($_GET['deleted_logs']) ? 'Platform turned off and logs deleted.' : 'Platform turned off.';
}

$stmt = $pdo->prepare(
    'SELECT e.*,
            (SELECT COUNT(*) FROM teams t WHERE t.event_id = e.id) AS team_count,
            (SELECT COUNT(*) FROM locations l WHERE l.event_id = e.id) AS location_count
     FROM events e
     LEFT JOIN event_leaders el ON el.event_id = e.id
     WHERE e.organiser_id = ? OR el.user_id = ?
     GROUP BY e.id
     ORDER BY e.is_active DESC, e.created_at DESC'
);
$stmt->execute([$userId, $userId]);
$events = $stmt->fetchAll();

$platformOn = get_platform_on($pdo);
$activeEventId = 0;
foreach ($events as $e) {
    if ((int)$e['is_active'] === 1) {
        $activeEventId = (int)$e['id'];
        break;
    }
}
$csrf = post_csrf_token();
$palette = team_colour_palette();
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Events – Grid Tracking</title>
    <link rel="icon" href="favicon.ico">
    <style>
        body {font-family:system-ui; background:#f4f4f4; padding:20px; color:#222; margin:0;}
        .container {max-width:1100px; margin:0 auto; background:white; padding:30px; border-radius:16px; box-shadow:0 6px 20px rgba(0,0,0,0.1);}
        h1 {color:#2E7D32; margin-top:0;}
        .event-card {border:1px solid #e7e7e7; border-radius:14px; padding:18px; margin-top:18px; background:#fff;}
        .notice {padding:12px 14px; border-radius:10px; margin:14px 0;}
        .notice.ok {background:#edf7ed; color:#216e39;}
        .notice.error {background:#fdeaea; color:#8a1f1f;}
        .notice.pins {background:#fff8e1; color:#5d4300;}
        form {margin:0;}
        input[type="text"], input[type="email"], input[type="password"], textarea, select {width:100%; padding:12px; margin:8px 0 14px; box-sizing:border-box; border:2px solid #ddd; border-radius:10px; font-size:1rem;}
        textarea {min-height:120px; resize:vertical;}
        .btn {display:inline-block; border:none; border-radius:10px; padding:11px 16px; cursor:pointer; text-decoration:none; font-size:0.98rem;}
        .btn-primary {background:#1565C0; color:#fff;}
        .btn-secondary {background:#f3f3f3; color:#222; border:1px solid #ccc;}
        .btn-danger {background:#b3261e; color:#fff;}
        .btn-success {background:#2E7D32; color:#fff;}
        .btn-muted {background:#6c757d; color:#fff;}
        .grid {display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:18px;}
        .subcard {background:#fafafa; border:1px solid #eee; border-radius:12px; padding:16px;}
        .badge {display:inline-block; padding:5px 10px; border-radius:999px; font-size:0.85rem; font-weight:700;}
        .badge-on {background:#edf7ed; color:#216e39;}
        .badge-off {background:#fdeaea; color:#8a1f1f;}
        .row-actions {display:flex; flex-wrap:wrap; gap:8px; margin-top:10px;}
        ul.clean {padding-left:18px; margin:8px 0 0;}
        .muted {color:#666; font-size:0.95rem;}
        .swatches {display:flex; flex-wrap:wrap; gap:8px; margin-top:8px;}
        .swatch {display:flex; align-items:center; gap:6px; font-size:0.9rem; background:#f7f7f7; border-radius:999px; padding:6px 10px;}
        .dot {display:inline-block; width:14px; height:14px; border-radius:50%; border:1px solid rgba(0,0,0,0.15);}
        .leader-list li {margin-bottom:8px;}
        .pin-table {width:100%; border-collapse:collapse; margin-top:8px;}
        .pin-table th, .pin-table td {text-align:left; padding:8px; border-bottom:1px solid #eee;}
        @media print {.no-print {display:none !important;}}
    </style>
</head>
<body>
<div class="container">
    <h1>🌲 Events & Teams</h1>
    <p><a href="admin.php" class="btn btn-secondary">← Back to control panel</a></p>

    <?php if ($message !== ''): ?><div class="notice ok"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

    <?php if ($generatedPins): ?>
        <div class="notice pins">
            <strong>New PINs — note these down now.</strong>
            <table class="pin-table">
                <thead><tr><th>Team</th><th>PIN</th></tr></thead>
                <tbody>
                <?php foreach ($generatedPins as $row): ?>
                    <tr><td><?= h($row['team']) ?></td><td><strong><?= h($row['pin']) ?></strong></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="muted">PINs are stored only as hashes, so they cannot be viewed again later. You can print cards from the event section below.</p>
        </div>
    <?php endif; ?>

    <div class="grid">
        <div class="subcard">
            <h2 style="margin-top:0;">Create event</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="create_event">
                <label>Event name</label>
                <input type="text" name="event_name" maxlength="100" placeholder="Troop Hike 22nd April" required>
                <button class="btn btn-primary" type="submit">Create event</button>
            </form>
        </div>

        <div class="subcard">
            <h2 style="margin-top:0;">Platform status</h2>
            <p>Public logging is currently
                <?php if ($platformOn): ?>
                    <span class="badge badge-on">ON</span>
                <?php else: ?>
                    <span class="badge badge-off">OFF</span>
                <?php endif; ?>
            </p>
            <form method="post" style="display:inline-block; margin-right:8px;">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="set_platform">
                <input type="hidden" name="platform_on" value="1">
                <button class="btn btn-success" type="submit">Turn platform on</button>
            </form>
            <form method="post" style="display:inline-block; margin-right:8px;" onsubmit="return confirm('Turn the platform off?');">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="set_platform">
                <input type="hidden" name="platform_on" value="0">
                <input type="hidden" name="active_event_id" value="<?= $activeEventId ?>">
                <button class="btn btn-danger" type="submit">Turn platform off</button>
            </form>
            <form method="post" style="display:inline-block;" onsubmit="return confirm('Turn the platform off and delete logged locations for the active event?');">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="set_platform">
                <input type="hidden" name="platform_on" value="0">
                <input type="hidden" name="delete_logs" value="1">
                <input type="hidden" name="active_event_id" value="<?= $activeEventId ?>">
                <button class="btn btn-muted" type="submit">Turn off &amp; delete logs</button>
            </form>
            <p class="muted">Event activation still happens per event below.</p>
        </div>
    </div>

    <div class="subcard" style="margin-top:18px;">
        <h2 style="margin-top:0;">Automatic team colours</h2>
        <p class="muted">New teams are assigned from a stronger, more distinct palette before any colour is reused on the same event.</p>
        <div class="swatches">
            <?php foreach ($palette as $colour): ?>
                <span class="swatch"><span class="dot" style="background:<?= h($colour) ?>"></span><?= h($colour) ?></span>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (!$events): ?>
        <p class="muted">No events yet.</p>
    <?php endif; ?>

    <?php foreach ($events as $event): ?>
        <?php
        $eventId = (int)$event['id'];
        $isOwner = user_owns_event($pdo, $userId, $eventId);

        $stmt = $pdo->prepare('SELECT id, team_name, color FROM teams WHERE event_id = ? ORDER BY team_name');
        $stmt->execute([$eventId]);
        $teams = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            'SELECT u.id, u.full_name, u.username
             FROM event_leaders el
             INNER JOIN users u ON u.id = el.user_id
             WHERE el.event_id = ?
             ORDER BY COALESCE(u.full_name, u.username), u.username'
        );
        $stmt->execute([$eventId]);
        $leaders = $stmt->fetchAll();
        ?>
        <div class="event-card">
            <h2 style="margin-top:0;"><?= h($event['event_name']) ?></h2>
            <p class="muted">Created <?= h((string)$event['created_at']) ?> • <?= (int)$event['team_count'] ?> teams • <?= (int)$event['location_count'] ?> location updates</p>
            <p>
                <?php if ((int)$event['is_active'] === 1): ?>
                    <span class="badge badge-on">ACTIVE EVENT</span>
                <?php else: ?>
                    <span class="badge badge-off">INACTIVE</span>
                <?php endif; ?>
            </p>

            <div class="row-actions">
                <?php if ($isOwner && (int)$event['is_active'] !== 1): ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="activate_event">
                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                        <button class="btn btn-success" type="submit">Activate event</button>
                    </form>
                <?php endif; ?>

                <?php if ($isOwner && (int)$event['is_active'] === 1): ?>
                    <form method="post" onsubmit="return confirm('Turn this event off?');">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="deactivate_event">
                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                        <button class="btn btn-danger" type="submit">Turn event off</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Turn this event off and delete its logged locations?');">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="deactivate_event">
                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                        <input type="hidden" name="delete_logs" value="1">
                        <button class="btn btn-muted" type="submit">Turn off &amp; delete logs</button>
                    </form>
                <?php endif; ?>

                <a class="btn btn-secondary" href="pin-cards.php?event_id=<?= $eventId ?>">Printable PIN cards</a>
                <?php if ($isOwner): ?>
                    <form method="post" onsubmit="return confirm('Delete this event, all its teams, extra leaders and logged locations? This cannot be undone.');">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="delete_event">
                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                        <button class="btn btn-danger" type="submit">Delete event</button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="grid" style="margin-top:16px;">
                <div class="subcard">
                    <h3 style="margin-top:0;">Add one team</h3>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="add_team">
                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                        <label>Team name</label>
                        <input type="text" name="team_name" maxlength="100" placeholder="Team 4" required>
                        <label>Colour override (optional)</label>
                        <input type="text" name="color" maxlength="7" placeholder="#2E86AB">
                        <label>PIN (optional)</label>
                        <input type="text" name="team_pin" inputmode="numeric" pattern="[0-9]*" maxlength="8" placeholder="Leave blank to auto-generate">
                        <button class="btn btn-primary" type="submit">Add team</button>
                    </form>
                </div>

                <div class="subcard">
                    <h3 style="margin-top:0;">Add several teams</h3>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="add_teams_bulk">
                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                        <label>One team per line</label>
                        <textarea name="team_names" placeholder="Team 4&#10;Team 5&#10;Team 6"></textarea>
                        <button class="btn btn-primary" type="submit">Add teams in bulk</button>
                    </form>
                </div>
            </div>

            <div class="grid" style="margin-top:16px;">
                <div class="subcard">
                    <h3 style="margin-top:0;">Teams</h3>
                    <?php if ($teams): ?>
                        <ul class="clean">
                            <?php foreach ($teams as $team): ?>
                                <li>
                                    <span class="dot" style="background:<?= h((string)($team['color'] ?: '#7413DC')) ?>"></span>
                                    <?= h($team['team_name']) ?>
                                    <span class="muted">(<?= h((string)($team['color'] ?: '#7413DC')) ?>)</span>
                                    <form method="post" style="display:inline-block; margin-left:8px;">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="action" value="reset_team_pin">
                                        <input type="hidden" name="team_id" value="<?= (int)$team['id'] ?>">
                                        <input type="text" name="team_pin" inputmode="numeric" pattern="[0-9]*" maxlength="8" placeholder="New PIN" style="width:110px; padding:6px 8px; margin:0 6px 0 8px;">
                                        <button class="btn btn-secondary" type="submit" style="padding:6px 10px; font-size:0.85rem;">Reset PIN</button>
                                    </form>
                                    <form method="post" style="display:inline-block; margin-left:6px;" onsubmit="return confirm('Delete this team?');">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="action" value="delete_team">
                                        <input type="hidden" name="team_id" value="<?= (int)$team['id'] ?>">
                                        <button class="btn btn-danger" type="submit" style="padding:6px 10px; font-size:0.85rem;">Delete</button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="muted">No teams yet.</p>
                    <?php endif; ?>
                </div>

                <div class="subcard">
                    <h3 style="margin-top:0;">Extra leaders</h3>
                    <?php if ($leaders): ?>
                        <ul class="clean leader-list">
                            <?php foreach ($leaders as $leader): ?>
                                <li>
                                    <?= h($leader['full_name'] ?: $leader['username']) ?>
                                    <span class="muted">(<?= h($leader['username']) ?>)</span>
                                    <?php if ($isOwner): ?>
                                        <form method="post" style="display:inline-block; margin-left:8px;" onsubmit="return confirm('Remove this leader from the event?');">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                            <input type="hidden" name="action" value="remove_leader">
                                            <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                            <input type="hidden" name="leader_id" value="<?= (int)$leader['id'] ?>">
                                            <button class="btn btn-danger" type="submit" style="padding:6px 10px; font-size:0.85rem;">Remove</button>
                                        </form>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="muted">No extra leaders yet.</p>
                    <?php endif; ?>

                    <?php if ($isOwner): ?>
                        <hr style="border:none; border-top:1px solid #eee; margin:16px 0;">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="add_existing_leader">
                            <input type="hidden" name="event_id" value="<?= $eventId ?>">
                            <label>Add existing account by username</label>
                            <input type="text" name="leader_username" placeholder="e.g. Bloory" required>
                            <button class="btn btn-secondary" type="submit">Add existing leader</button>
                        </form>

                        <hr style="border:none; border-top:1px solid #eee; margin:16px 0;">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="create_and_add_leader">
                            <input type="hidden" name="event_id" value="<?= $eventId ?>">
                            <label>Create a new leader login</label>
                            <input type="text" name="leader_name" maxlength="100" placeholder="Leader name (optional)">
                            <input type="text" name="leader_username_new" placeholder="e.g. Blossom" required>
                            <input type="password" name="leader_password" placeholder="Temporary password (min 10 chars)" required>
                            <button class="btn btn-primary" type="submit">Create and add leader</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
</body>
</html>