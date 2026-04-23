<?php
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'db_username');
define('DB_PASS', 'db_password');
define('DB_NAME', 'db_name');

define('DATA_RETENTION_HOURS', 24);
define('RATE_LIMIT_MINUTES', 5);
define('TEAM_PIN_LENGTH', 4);

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database connection failed.');
}

function h(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function is_admin_logged_in(): bool {
    return !empty($_SESSION['is_admin']) && !empty($_SESSION['user_id']);
}

function current_user_id(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function require_admin(): void {
    if (!is_admin_logged_in()) {
        header('Location: admin.php');
        exit;
    }
}

function json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function post_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        exit('Invalid request token.');
    }
}

function flash_set(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array {
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

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

function get_active_event(PDO $pdo, ?int $userId = null): ?array {
    if ($userId) {
        $stmt = $pdo->prepare(
            'SELECT e.id, e.organiser_id, e.event_name, e.is_active
             FROM events e
             LEFT JOIN event_leaders el ON el.event_id = e.id
             WHERE e.is_active = 1 AND (e.organiser_id = ? OR el.user_id = ?)
             ORDER BY e.id DESC
             LIMIT 1'
        );
        $stmt->execute([$userId, $userId]);
    } else {
        $stmt = $pdo->query(
            'SELECT id, organiser_id, event_name, is_active
             FROM events
             WHERE is_active = 1
             ORDER BY id DESC
             LIMIT 1'
        );
    }
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_platform_on(PDO $pdo): bool {
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE name = 'platform_on' LIMIT 1");
    $stmt->execute();
    return (string)$stmt->fetchColumn() === '1';
}

function set_platform_on(PDO $pdo, bool $value): void {
    $stmt = $pdo->prepare(
        "INSERT INTO settings (name, value) VALUES ('platform_on', ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)"
    );
    $stmt->execute([$value ? 1 : 0]);
}

function cleanup_old_locations(PDO $pdo): int {
    $stmt = $pdo->prepare('DELETE FROM locations WHERE timestamp < (NOW() - INTERVAL ? HOUR)');
    $stmt->execute([DATA_RETENTION_HOURS]);
    return $stmt->rowCount();
}

function delete_event_locations(PDO $pdo, int $eventId): int {
    $stmt = $pdo->prepare('DELETE FROM locations WHERE event_id = ?');
    $stmt->execute([$eventId]);
    return $stmt->rowCount();
}

function distinct_team_palette(): array {
    return [
        '#D7263D', '#F49D37', '#FFD23F', '#2E86AB',
        '#6C5CE7', '#00A676', '#FF6F91', '#3A3A3A',
        '#1B9AAA', '#F26419', '#8E5572', '#33658A',
    ];
}

function get_next_team_colour(PDO $pdo, int $eventId): string {
    $palette = distinct_team_palette();
    $stmt = $pdo->prepare('SELECT color FROM teams WHERE event_id = ?');
    $stmt->execute([$eventId]);
    $used = array_filter(array_map(
        static fn($row) => strtoupper((string)($row['color'] ?? '')),
        $stmt->fetchAll()
    ));

    foreach ($palette as $colour) {
        if (!in_array(strtoupper($colour), $used, true)) {
            return $colour;
        }
    }

    $count = count($used);
    return $palette[$count % count($palette)];
}

function generate_team_pin(int $length = TEAM_PIN_LENGTH): string {
    $digits = '';
    for ($i = 0; $i < $length; $i++) {
        $digits .= (string) random_int(0, 9);
    }
    return $digits;
}

function normalise_pin(string $pin): string {
    return preg_replace('/\D+/', '', $pin) ?? '';
}

cleanup_old_locations($pdo);
?>
