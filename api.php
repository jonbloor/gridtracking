<?php
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$action = $_GET['action'] ?? '';

function ensure_team_device_tokens_table(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS team_device_tokens (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            team_name VARCHAR(100) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NULL,
            UNIQUE KEY uniq_token_hash (token_hash),
            KEY idx_event_team (event_id, team_name),
            KEY idx_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec("DELETE FROM team_device_tokens WHERE expires_at < NOW()");
}

function issue_team_device_token(PDO $pdo, int $eventId, string $teamName, int $hours = 12): string {
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = (new DateTimeImmutable('+' . $hours . ' hours'))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO team_device_tokens (event_id, team_name, token_hash, expires_at)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$eventId, $teamName, $tokenHash, $expiresAt]);

    return $token;
}

function validate_team_device_token(PDO $pdo, int $eventId, string $teamName, string $token, int $hours = 12): bool {
    if ($token === '') {
        return false;
    }

    $tokenHash = hash('sha256', $token);

    $stmt = $pdo->prepare(
        'SELECT id
         FROM team_device_tokens
         WHERE event_id = ? AND team_name = ? AND token_hash = ? AND expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([$eventId, $teamName, $tokenHash]);
    $row = $stmt->fetch();

    if (!$row) {
        return false;
    }

    $newExpiry = (new DateTimeImmutable('+' . $hours . ' hours'))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'UPDATE team_device_tokens
         SET last_used_at = NOW(), expires_at = ?
         WHERE id = ?'
    );
    $stmt->execute([$newExpiry, (int)$row['id']]);

    return true;
}

try {
    ensure_team_device_tokens_table($pdo);

    if ($action === 'status') {
        $event = get_active_event($pdo);
        json_response([
            'active' => get_platform_on($pdo) && $event !== null,
            'event_id' => $event['id'] ?? null,
            'event_name' => $event['event_name'] ?? null,
        ]);
    }

    if ($action === 'active_teams') {
        $event = get_active_event($pdo);
        if (!$event || !get_platform_on($pdo)) {
            json_response(['teams' => []]);
        }

        $stmt = $pdo->prepare('SELECT id, team_name, color FROM teams WHERE event_id = ? ORDER BY team_name');
        $stmt->execute([(int)$event['id']]);
        json_response(['teams' => $stmt->fetchAll()]);
    }

    if ($action === 'admin') {
        if (!is_admin_logged_in()) {
            json_response(['error' => 'Not logged in'], 401);
        }

        $userId = current_user_id();
        $activeEvent = get_active_event($pdo, $userId);

        if (!$activeEvent) {
            json_response([
                'event_name' => null,
                'locations' => [],
                'stats' => [
                    'team_count' => 0,
                    'teams_plotted' => 0,
                    'teams_stale' => 0,
                    'total_updates' => 0,
                ],
            ]);
        }

        $eventId = (int)$activeEvent['id'];

        $stmt = $pdo->prepare(
            'SELECT
                t.team_name AS team,
                COALESCE(t.color, "#7413DC") AS color,
                l.lat,
                l.lng,
                DATE_FORMAT(l.timestamp, "%H:%i on %d %b") AS time,
                CASE WHEN l.timestamp IS NULL THEN NULL ELSE TIMESTAMPDIFF(MINUTE, l.timestamp, NOW()) END AS minutes_ago,
                CASE WHEN l.timestamp IS NULL THEN 0 ELSE 1 END AS has_location,
                (
                    SELECT COUNT(*)
                    FROM locations lh
                    WHERE lh.event_id = ?
                      AND lh.team = t.team_name
                      AND lh.timestamp > (NOW() - INTERVAL ? HOUR)
                ) AS history_count
            FROM teams t
            LEFT JOIN (
                SELECT l1.*
                FROM locations l1
                INNER JOIN (
                    SELECT team, MAX(timestamp) AS max_time
                    FROM locations
                    WHERE event_id = ?
                      AND timestamp > (NOW() - INTERVAL ? HOUR)
                    GROUP BY team
                ) latest
                    ON latest.team = l1.team
                   AND latest.max_time = l1.timestamp
                WHERE l1.event_id = ?
            ) l
                ON l.team = t.team_name
            WHERE t.event_id = ?
            ORDER BY t.team_name'
        );
        $stmt->execute([$eventId, DATA_RETENTION_HOURS, $eventId, DATA_RETENTION_HOURS, $eventId, $eventId]);
        $locations = $stmt->fetchAll();

        $teamCount = count($locations);
        $teamsPlotted = 0;
        $teamsStale = 0;

        foreach ($locations as $row) {
            if ((int)$row['has_location'] === 1) {
                $teamsPlotted++;
            }
            if ($row['minutes_ago'] !== null && (int)$row['minutes_ago'] > RATE_LIMIT_MINUTES) {
                $teamsStale++;
            }
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM locations WHERE event_id = ?');
        $stmt->execute([$eventId]);
        $totalUpdates = (int)$stmt->fetchColumn();

        json_response([
            'event_name' => $activeEvent['event_name'],
            'locations' => $locations,
            'stats' => [
                'team_count' => $teamCount,
                'teams_plotted' => $teamsPlotted,
                'teams_stale' => $teamsStale,
                'total_updates' => $totalUpdates,
            ],
        ]);
    }

    if ($action === 'team_history') {
        if (!is_admin_logged_in()) {
            json_response(['error' => 'Not logged in'], 401);
        }

        $team = trim((string)($_GET['team'] ?? ''));
        if ($team === '') {
            json_response(['error' => 'No team supplied'], 422);
        }

        $activeEvent = get_active_event($pdo, current_user_id());
        if (!$activeEvent) {
            json_response(['error' => 'No active event'], 404);
        }

        $stmt = $pdo->prepare(
            'SELECT team_name, color
             FROM teams
             WHERE event_id = ? AND team_name = ?
             LIMIT 1'
        );
        $stmt->execute([(int)$activeEvent['id'], $team]);
        $teamRow = $stmt->fetch();

        if (!$teamRow) {
            json_response(['error' => 'Team not found for the active event'], 404);
        }

        $stmt = $pdo->prepare(
            'SELECT
                lat,
                lng,
                DATE_FORMAT(timestamp, "%H:%i:%s on %d %b %Y") AS time,
                timestamp
             FROM locations
             WHERE event_id = ? AND team = ?
               AND timestamp > (NOW() - INTERVAL ? HOUR)
             ORDER BY timestamp DESC'
        );
        $stmt->execute([(int)$activeEvent['id'], $team, DATA_RETENTION_HOURS]);
        $history = $stmt->fetchAll();

        json_response([
            'event_name' => $activeEvent['event_name'],
            'team' => $teamRow['team_name'],
            'color' => $teamRow['color'] ?: '#7413DC',
            'history' => $history,
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            json_response(['success' => false, 'error' => 'Invalid request payload'], 400);
        }

        $team = trim((string)($data['team'] ?? ''));
        $pin = normalise_pin((string)($data['pin'] ?? ''));
        $teamToken = trim((string)($data['team_token'] ?? ''));
        $rememberDevice = !empty($data['remember_device']);
        $lat = $data['lat'] ?? null;
        $lng = $data['lng'] ?? null;

        if ($team === '' || !is_numeric($lat) || !is_numeric($lng)) {
            json_response(['success' => false, 'error' => 'Team and location are required'], 422);
        }

        if ($pin === '' && $teamToken === '') {
            json_response(['success' => false, 'error' => 'Team PIN or saved team access is required', 'require_pin' => true], 422);
        }

        if (!get_platform_on($pdo)) {
            json_response(['success' => false, 'error' => 'Tracking is currently off'], 409);
        }

        $activeEvent = get_active_event($pdo);
        if (!$activeEvent) {
            json_response(['success' => false, 'error' => 'No active event'], 409);
        }

        $eventId = (int)$activeEvent['id'];

        $stmt = $pdo->prepare(
            'SELECT id, team_pin_hash
             FROM teams
             WHERE event_id = ? AND team_name = ?
             LIMIT 1'
        );
        $stmt->execute([$eventId, $team]);
        $teamRow = $stmt->fetch();

        if (!$teamRow) {
            json_response(['success' => false, 'error' => 'That team is not part of the active event'], 422);
        }

        $authorisedByToken = false;

        if ($teamToken !== '') {
            $authorisedByToken = validate_team_device_token($pdo, $eventId, $team, $teamToken);
            if (!$authorisedByToken && $pin === '') {
                json_response([
                    'success' => false,
                    'error' => 'Saved access has expired. Please enter the team PIN again.',
                    'invalid_saved_team_token' => true,
                    'require_pin' => true
                ], 403);
            }
        }

        if (!$authorisedByToken) {
            if (empty($teamRow['team_pin_hash']) || !password_verify($pin, (string)$teamRow['team_pin_hash'])) {
                json_response(['success' => false, 'error' => 'Incorrect team PIN'], 403);
            }
        }

        $stmt = $pdo->prepare(
            'SELECT timestamp
             FROM locations
             WHERE event_id = ? AND team = ?
             ORDER BY timestamp DESC
             LIMIT 1'
        );
        $stmt->execute([$eventId, $team]);
        $lastTimestamp = $stmt->fetchColumn();

        if ($lastTimestamp) {
            $secondsSince = time() - strtotime((string)$lastTimestamp);
            if ($secondsSince < RATE_LIMIT_MINUTES * 60) {
                $waitSeconds = (RATE_LIMIT_MINUTES * 60) - $secondsSince;
                json_response([
                    'success' => false,
                    'error' => 'Please wait before sending another update',
                    'retry_in_seconds' => $waitSeconds,
                ], 429);
            }
        }

    // Updated INSERT – name column was removed in recent build
    $stmt = $pdo->prepare(
        'INSERT INTO locations (team, lat, lng, event_id)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([
        $team,
        (float)$lat,
        (float)$lng,
        $eventId,
    ]);      
      
        $response = ['success' => true];

        if (!$authorisedByToken && $rememberDevice) {
            $response['team_token'] = issue_team_device_token($pdo, $eventId, $team, 12);
        }

        json_response($response);
    }

    json_response(['error' => 'Invalid request'], 404);
} catch (Throwable $e) {
    error_log('Grid Tracking API error: ' . $e->getMessage());
    json_response(['error' => 'Server error'], 500);
}
?>