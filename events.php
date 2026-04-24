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

function ensure_event_settings_columns_events_page(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

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

ensure_team_pin_column($pdo);
ensure_event_settings_columns_events_page($pdo);

$userId = current_user_id();
$message = '';
$error = '';
$generatedPins = $_SESSION['generated_pins'] ?? [];
unset($_SESSION['generated_pins']);

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
                    delete_event_locations($pdo, $eventId);
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
$activeEvent = null;

foreach ($events as $e) {
    if ((int)$e['is_active'] === 1) {
        $activeEventId = (int)$e['id'];
        $activeEvent = $e;
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

    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="192x192" href="android-chrome-192x192.png">
    <link rel="apple-touch-icon" href="android-chrome-192x192.png">
    <link rel="manifest" href="site.webmanifest">

    <style>
        :root {
            --brand: #2E7D32;
            --blue: #1565C0;
            --danger: #b3261e;
            --muted: #5f6b76;
            --bg: #f4f4f4;
            --surface: #ffffff;
            --soft: #fafafa;
            --border: #e7e7e7;
            --text: #222;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: var(--bg);
            padding: 20px;
            color: var(--text);
            margin: 0;
        }

        .container {
            max-width: 1120px;
            margin: 0 auto;
            background: var(--surface);
            padding: 26px;
            border-radius: 18px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.1);
        }

        h1 {
            color: var(--brand);
            margin: 0;
        }

        h2 {
            margin: 0 0 12px;
        }

        h3 {
            margin: 0 0 10px;
            font-size: 1.05rem;
        }

        label {
            font-weight: 700;
        }

        .page-head {
            display: flex;
            gap: 14px;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .page-actions,
        .row-actions,
        .button-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .quick-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 16px;
            margin-bottom: 18px;
        }

        .subcard,
        .event-card,
        .notice,
        details.utility-box {
            border-radius: 14px;
        }

        .subcard {
            background: var(--soft);
            border: 1px solid #eee;
            padding: 16px;
        }

        .event-card {
            border: 1px solid var(--border);
            margin-top: 16px;
            background: #fff;
            overflow: hidden;
        }

        .event-card summary {
            list-style: none;
            cursor: pointer;
            padding: 18px;
        }

        .event-card summary::-webkit-details-marker {
            display: none;
        }

        .event-title-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

        .event-title {
            font-size: 1.25rem;
            font-weight: 800;
        }

        .event-meta {
            color: var(--muted);
            font-size: 0.95rem;
            margin-top: 6px;
        }

        .event-body {
            border-top: 1px solid var(--border);
            padding: 18px;
        }

        .event-sections {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 16px;
        }

        .wide {
            grid-column: 1 / -1;
        }

        .danger-zone {
            border-color: #f3c5c0;
            background: #fff8f7;
        }

        .danger-zone h3 {
            color: var(--danger);
        }

        .notice {
            padding: 12px 14px;
            margin: 14px 0;
        }

        .notice.ok {
            background: #edf7ed;
            color: #216e39;
        }

        .notice.error {
            background: #fdeaea;
            color: #8a1f1f;
        }

        .notice.pins {
            background: #fff8e1;
            color: #5d4300;
        }

        form {
            margin: 0;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"],
        textarea,
        select {
            width: 100%;
            padding: 12px;
            margin: 8px 0 14px;
            box-sizing: border-box;
            border: 2px solid #ddd;
            border-radius: 10px;
            font-size: 1rem;
        }

        textarea {
            min-height: 118px;
            resize: vertical;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: none;
            border-radius: 10px;
            padding: 11px 16px;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.98rem;
            min-height: 42px;
            line-height: 1.2;
        }

        .btn-primary {
            background: var(--blue);
            color: #fff;
        }

        .btn-secondary {
            background: #f3f3f3;
            color: #222;
            border: 1px solid #ccc;
        }

        .btn-danger {
            background: var(--danger);
            color: #fff;
        }

        .btn-success {
            background: var(--brand);
            color: #fff;
        }

        .btn-muted {
            background: #6c757d;
            color: #fff;
        }

        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .badge-on {
            background: #edf7ed;
            color: #216e39;
        }

        .badge-off {
            background: #f1f1f1;
            color: #555;
        }

        .badge-platform-off {
            background: #fdeaea;
            color: #8a1f1f;
        }

        .muted {
            color: var(--muted);
            font-size: 0.95rem;
        }

        .small {
            font-size: 0.9rem;
        }

        ul.clean {
            padding-left: 18px;
            margin: 8px 0 0;
        }

        .swatches {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }

        .swatch {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.9rem;
            background: #fff;
            border-radius: 999px;
            padding: 6px 10px;
            border: 1px solid #eee;
        }

        .dot {
            display: inline-block;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 1px solid rgba(0,0,0,0.15);
        }

        .team-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 10px 0 0;
            padding: 0;
            list-style: none;
        }

        .team-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid #e5e5e5;
            border-radius: 999px;
            padding: 6px 10px;
            background: #fff;
            font-weight: 700;
        }

        .team-management {
            margin-top: 12px;
        }

        .team-row {
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 8px;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }

        .team-row:last-child {
            border-bottom: none;
        }

        .inline-form {
            display: inline;
        }

        .leader-list li {
            margin-bottom: 8px;
        }

        .pin-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }

        .pin-table th,
        .pin-table td {
            text-align: left;
            padding: 8px;
            border-bottom: 1px solid #eee;
        }

        details.utility-box {
            background: #fafafa;
            border: 1px solid #eee;
            padding: 0;
            margin-top: 18px;
        }

        details.utility-box summary {
            cursor: pointer;
            padding: 16px;
            font-weight: 800;
        }

        details.utility-box .details-content {
            padding: 0 16px 16px;
        }

        .settings-strip {
            background: #eef6ff;
            border: 1px solid #b9d6ff;
            border-radius: 12px;
            padding: 12px;
            color: #173b73;
            margin-bottom: 14px;
        }

        @media (max-width: 700px) {
            body {
                padding: 10px;
            }

            .container {
                padding: 18px;
            }

            .btn {
                width: 100%;
            }

            .row-actions .btn,
            .button-row .btn,
            .page-actions .btn {
                flex: 1 1 100%;
            }

            .team-row {
                grid-template-columns: 1fr;
            }
        }

        @media print {
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>

<body>
<div class="container">
    <div class="page-head">
        <div>
            <h1>🌲 Events &amp; Teams</h1>
            <p class="muted">Set up events, teams, PINs and leader access for Grid Tracking.</p>
        </div>
        <div class="page-actions no-print">
            <a href="admin.php" class="btn btn-secondary">← Control panel</a>
            <a href="event-settings.php" class="btn btn-secondary">Event settings</a>
            <a href="pin-cards.php<?= $activeEventId ? '?event_id=' . $activeEventId : '' ?>" class="btn btn-primary">Print PIN cards</a>
        </div>
    </div>

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
            <p class="muted">PINs are stored only as hashes, so they cannot be viewed again later.</p>
        </div>
    <?php endif; ?>

    <?php if ($activeEvent): ?>
        <div class="settings-strip">
            <strong>Active event:</strong> <?= h($activeEvent['event_name']) ?>
            · Cooldown <?= (int)($activeEvent['rate_limit_minutes'] ?? 5) ?> min
            · Reminder <?= (int)($activeEvent['reminder_minutes'] ?? 10) ?> min
            · Stale after <?= (int)($activeEvent['stale_minutes'] ?? 10) ?> min
        </div>
    <?php endif; ?>

    <div class="quick-grid no-print">
        <div class="subcard">
            <h2>Create event</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="create_event">

                <label for="event_name">Event name</label>
                <input id="event_name" type="text" name="event_name" maxlength="100" placeholder="Troop Hike 22nd April" required>

                <button class="btn btn-primary" type="submit">Create event</button>
            </form>
        </div>

        <div class="subcard">
            <h2>Platform status</h2>
            <p>Public logging is currently
                <?php if ($platformOn): ?>
                    <span class="badge badge-on">ON</span>
                <?php else: ?>
                    <span class="badge badge-platform-off">OFF</span>
                <?php endif; ?>
            </p>

            <div class="button-row">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="set_platform">
                    <input type="hidden" name="platform_on" value="1">
                    <button class="btn btn-success" type="submit">Turn platform on</button>
                </form>

                <form method="post" onsubmit="return confirm('Turn the platform off?');">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="set_platform">
                    <input type="hidden" name="platform_on" value="0">
                    <input type="hidden" name="active_event_id" value="<?= $activeEventId ?>">
                    <button class="btn btn-danger" type="submit">Turn platform off</button>
                </form>
            </div>

            <p class="muted">Use the event cards below to activate or deactivate a specific event.</p>
        </div>
    </div>

    <details class="utility-box no-print">
        <summary>Automatic team colours</summary>
        <div class="details-content">
            <p class="muted">New teams are assigned from this palette automatically. You only need to change colours if two teams are hard to distinguish on the map.</p>
            <div class="swatches">
                <?php foreach ($palette as $colour): ?>
                    <span class="swatch"><span class="dot" style="background:<?= h($colour) ?>"></span><?= h($colour) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </details>

    <?php if (!$events): ?>
        <p class="muted">No events yet.</p>
    <?php endif; ?>

    <?php foreach ($events as $event): ?>
        <?php
        $eventId = (int)$event['id'];
        $isOwner = user_owns_event($pdo, $userId, $eventId);
        $isActive = (int)$event['is_active'] === 1;

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

        $openAttr = $isActive ? 'open' : '';
        ?>
        <details class="event-card" <?= $openAttr ?>>
            <summary>
                <div class="event-title-row">
                    <div>
                        <div class="event-title"><?= h($event['event_name']) ?></div>
                        <div class="event-meta">
                            Created <?= h((string)$event['created_at']) ?>
                            · <?= (int)$event['team_count'] ?> team<?= (int)$event['team_count'] === 1 ? '' : 's' ?>
                            · <?= (int)$event['location_count'] ?> location update<?= (int)$event['location_count'] === 1 ? '' : 's' ?>
                        </div>
                    </div>
                    <div>
                        <?php if ($isActive): ?>
                            <span class="badge badge-on">Active</span>
                        <?php else: ?>
                            <span class="badge badge-off">Inactive</span>
                        <?php endif; ?>
                    </div>
                </div>
            </summary>

            <div class="event-body">
                <?php if ($isActive): ?>
                    <div class="settings-strip no-print">
                        <strong>Timings:</strong>
                        cooldown <?= (int)($event['rate_limit_minutes'] ?? 5) ?> min,
                        reminder <?= (int)($event['reminder_minutes'] ?? 10) ?> min,
                        stale after <?= (int)($event['stale_minutes'] ?? 10) ?> min.
                        <a href="event-settings.php">Change settings</a>
                    </div>
                <?php endif; ?>

                <div class="row-actions no-print" style="margin-bottom:16px;">
                    <?php if ($isOwner && !$isActive): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="activate_event">
                            <input type="hidden" name="event_id" value="<?= $eventId ?>">
                            <button class="btn btn-success" type="submit">Activate event</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($isOwner && $isActive): ?>
                        <form method="post" onsubmit="return confirm('Turn this event off?');">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="deactivate_event">
                            <input type="hidden" name="event_id" value="<?= $eventId ?>">
                            <button class="btn btn-danger" type="submit">Turn event off</button>
                        </form>
                    <?php endif; ?>

                    <a href="pin-cards.php?event_id=<?= $eventId ?>" class="btn btn-primary">Print PIN cards</a>

                    <?php if ($isActive): ?>
                        <a href="event-settings.php" class="btn btn-secondary">Event settings</a>
                    <?php endif; ?>
                </div>

                <div class="event-sections">
                    <div class="subcard">
                        <h3>Add one team</h3>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="add_team">
                            <input type="hidden" name="event_id" value="<?= $eventId ?>">

                            <label>Team name</label>
                            <input type="text" name="team_name" maxlength="100" placeholder="Red Team" required>

                            <details>
                                <summary class="muted" style="cursor:pointer; margin-bottom:10px;">Optional colour or PIN</summary>

                                <label>Colour</label>
                                <input type="text" name="color" maxlength="7" placeholder="#2E86AB">

                                <label>PIN</label>
                                <input type="text" name="team_pin" inputmode="numeric" maxlength="6" placeholder="Leave blank to generate">
                            </details>

                            <button class="btn btn-primary" type="submit">Add team</button>
                        </form>
                    </div>

                    <div class="subcard">
                        <h3>Bulk add teams</h3>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="add_teams_bulk">
                            <input type="hidden" name="event_id" value="<?= $eventId ?>">

                            <label>One team per line</label>
                            <textarea name="team_names" placeholder="Red Team&#10;Blue Team&#10;Green Team"></textarea>

                            <button class="btn btn-primary" type="submit">Bulk add teams</button>
                        </form>
                    </div>

                    <div class="subcard wide">
                        <h3>Teams</h3>

                        <?php if (!$teams): ?>
                            <p class="muted">No teams yet.</p>
                        <?php else: ?>
                            <ul class="team-list">
                                <?php foreach ($teams as $team): ?>
                                    <li class="team-pill">
                                        <span class="dot" style="background:<?= h($team['color'] ?: '#7413DC') ?>"></span>
                                        <?= h($team['team_name']) ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>

                            <details class="team-management no-print">
                                <summary class="muted" style="cursor:pointer; margin-top:14px;">Manage team PINs and deletion</summary>

                                <?php foreach ($teams as $team): ?>
                                    <div class="team-row">
                                        <div>
                                            <span class="dot" style="background:<?= h($team['color'] ?: '#7413DC') ?>"></span>
                                            <strong><?= h($team['team_name']) ?></strong>
                                        </div>

                                        <form method="post" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                            <input type="hidden" name="action" value="reset_team_pin">
                                            <input type="hidden" name="team_id" value="<?= (int)$team['id'] ?>">
                                            <button class="btn btn-secondary" type="submit">Reset PIN</button>
                                        </form>

                                        <form method="post" class="inline-form" onsubmit="return confirm('Delete this team?');">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                            <input type="hidden" name="action" value="delete_team">
                                            <input type="hidden" name="team_id" value="<?= (int)$team['id'] ?>">
                                            <button class="btn btn-danger" type="submit">Delete</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </details>
                        <?php endif; ?>
                    </div>

                    <?php if ($isOwner): ?>
                        <div class="subcard">
                            <h3>Extra leaders</h3>

                            <?php if (!$leaders): ?>
                                <p class="muted">No extra leaders added yet.</p>
                            <?php else: ?>
                                <ul class="clean leader-list">
                                    <?php foreach ($leaders as $leader): ?>
                                        <li>
                                            <?= h($leader['full_name'] ?: $leader['username']) ?>
                                            <span class="muted">(<?= h($leader['username']) ?>)</span>

                                            <form method="post" class="inline-form" onsubmit="return confirm('Remove this leader from the event?');">
                                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                                <input type="hidden" name="action" value="remove_leader">
                                                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                                <input type="hidden" name="leader_id" value="<?= (int)$leader['id'] ?>">
                                                <button class="btn btn-secondary small" type="submit">Remove</button>
                                            </form>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <details style="margin-top:12px;">
                                <summary class="muted" style="cursor:pointer;">Add an existing leader</summary>
                                <form method="post" style="margin-top:12px;">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="action" value="add_existing_leader">
                                    <input type="hidden" name="event_id" value="<?= $eventId ?>">

                                    <label>Leader username</label>
                                    <input type="text" name="leader_username" placeholder="username">

                                    <button class="btn btn-primary" type="submit">Add leader</button>
                                </form>
                            </details>

                            <details style="margin-top:12px;">
                                <summary class="muted" style="cursor:pointer;">Create a new leader login</summary>
                                <form method="post" style="margin-top:12px;">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="action" value="create_and_add_leader">
                                    <input type="hidden" name="event_id" value="<?= $eventId ?>">

                                    <label>Leader name</label>
                                    <input type="text" name="leader_name" placeholder="Alex Smith">

                                    <label>Username</label>
                                    <input type="text" name="leader_username_new" placeholder="alex">

                                    <label>Password</label>
                                    <input type="password" name="leader_password" minlength="10" placeholder="At least 10 characters">

                                    <button class="btn btn-primary" type="submit">Create leader</button>
                                </form>
                            </details>
                        </div>
                    <?php endif; ?>

                    <?php if ($isOwner): ?>
                        <div class="subcard danger-zone">
                            <h3>Danger area</h3>
                            <p class="muted">These actions remove data or change event availability.</p>

                            <?php if ($isActive): ?>
                                <form method="post" onsubmit="return confirm('Turn this event off and delete all logged locations for it?');">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="action" value="deactivate_event">
                                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <input type="hidden" name="delete_logs" value="1">
                                    <button class="btn btn-muted" type="submit">Turn off &amp; delete logs</button>
                                </form>
                            <?php endif; ?>

                            <form method="post" style="margin-top:10px;" onsubmit="return confirm('Delete this event, its teams, leaders and location logs? This cannot be undone.');">
                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                <input type="hidden" name="action" value="delete_event">
                                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                <button class="btn btn-danger" type="submit">Delete event</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </details>
    <?php endforeach; ?>
</div>
</body>
</html>