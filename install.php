<?php
/**
 * Grid Tracking Install Script
 * Run this ONCE after copying config.example.php to config.php and editing your database credentials.
 * It will create all required tables (with username support for leaders) and insert a default organiser.
 * DELETE THIS FILE after running successfully for security.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$step = $_GET['step'] ?? '1';
$message = '';
$error = '';

// Try to load config if exists, else show instructions
$configLoaded = false;
if (file_exists(__DIR__ . '/config.php')) {
    require __DIR__ . '/config.php';
    if (isset($pdo) && $pdo instanceof PDO) {
        $configLoaded = true;
    }
}

if (!$configLoaded) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Grid Tracking Install</title>
        <style>body{font-family:system-ui; max-width:700px; margin:40px auto; padding:20px; line-height:1.5;}</style>
    </head>
    <body>
        <h1>🌲 Grid Tracking – First Time Setup</h1>
        <p><strong>config.php not found or invalid.</strong></p>
        <ol>
            <li>Copy <code>config.example.php</code> to <code>config.php</code></li>
            <li>Edit <code>config.php</code> with your MySQL/MariaDB credentials (host, dbname, user, pass)</li>
            <li>Make sure the database exists and the user has CREATE, ALTER, INSERT privileges</li>
            <li>Refresh this page</li>
        </ol>
        <p>After setup, <strong>DELETE install.php</strong> and optionally register.php if you used the default user.</p>
    </body>
    </html>
    <?php
    exit;
}

// Helper to check if table exists
function tableExists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
        return (bool)$stmt->fetch();
    } catch (Exception $e) {
        return false;
    }
}

if ($step === '2' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create tables
    try {
        // 1. users (with username as login key, email kept optional for legacy)
        if (!tableExists($pdo, 'users')) {
            $pdo->exec("
                CREATE TABLE users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(50) NOT NULL UNIQUE,
                    email VARCHAR(255) NULL,
                    password_hash VARCHAR(255) NOT NULL,
                    full_name VARCHAR(100) NULL,
                    is_organiser TINYINT(1) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $message .= "Created users table. ";
        } else {
            // Ensure username column exists (for upgrades)
            $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'username'");
            if (!$stmt->fetch()) {
                $pdo->exec("ALTER TABLE users ADD COLUMN username VARCHAR(50) NULL UNIQUE AFTER id");
                $message .= "Added username column to users. ";
            }
        }

        // 2. events
        if (!tableExists($pdo, 'events')) {
            $pdo->exec("
                CREATE TABLE events (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    organiser_id INT NOT NULL,
                    event_name VARCHAR(100) NOT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (organiser_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $message .= "Created events table. ";
        }

        // 3. teams
        if (!tableExists($pdo, 'teams')) {
            $pdo->exec("
                CREATE TABLE teams (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    event_id INT NOT NULL,
                    team_name VARCHAR(100) NOT NULL,
                    color VARCHAR(7) NULL,
                    team_pin_hash VARCHAR(255) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
                    UNIQUE KEY unique_team (event_id, team_name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $message .= "Created teams table. ";
        }

        // 4. event_leaders
        if (!tableExists($pdo, 'event_leaders')) {
            $pdo->exec("
                CREATE TABLE event_leaders (
                    event_id INT NOT NULL,
                    user_id INT NOT NULL,
                    PRIMARY KEY (event_id, user_id),
                    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $message .= "Created event_leaders table. ";
        }

        // 5. locations (includes legacy 'name' column)
        if (!tableExists($pdo, 'locations')) {
            $pdo->exec("
                CREATE TABLE locations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    event_id INT NOT NULL,
                    team_name VARCHAR(100) NOT NULL,
                    lat DECIMAL(10, 8) NULL,
                    lng DECIMAL(11, 8) NULL,
                    name VARCHAR(100) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $message .= "Created locations table (with legacy name column). ";
        }

        // 6. settings
        if (!tableExists($pdo, 'settings')) {
            $pdo->exec("
                CREATE TABLE settings (
                    name VARCHAR(50) PRIMARY KEY,
                    value VARCHAR(255) NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $message .= "Created settings table. ";
        }

        // Insert default first organiser if none exists
        $stmt = $pdo->query("SELECT COUNT(*) FROM users");
        if ((int)$stmt->fetchColumn() === 0) {
            $defaultUsername = 'admin';
            $defaultPass = 'ChangeMe123!'; // IMPORTANT: Change this immediately after login
            $hash = password_hash($defaultPass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, is_organiser) VALUES (?, ?, 'Default Organiser', 1)");
            $stmt->execute([$defaultUsername, $hash]);
            $message .= "<br><strong>Created default user: username = <code>admin</code>, password = <code>ChangeMe123!</code></strong> — LOGIN AND CHANGE PASSWORD IMMEDIATELY!";
        } else {
            $message .= "<br>Users table already has accounts — skipped default user creation.";
        }

        $message .= "<br><br>All tables created successfully! You can now login at <a href='admin.php'>admin.php</a>.";
        $step = 'done';

    } catch (Exception $e) {
        $error = "Setup failed: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grid Tracking Install</title>
    <style>
        body {font-family: system-ui; max-width: 720px; margin: 40px auto; padding: 20px; background: #f4f4f4; color: #222;}
        .container {background: white; padding: 30px; border-radius: 16px; box-shadow: 0 6px 20px rgba(0,0,0,0.1);}
        h1 {color: #2E7D32;}
        .notice {padding: 14px; border-radius: 10px; margin: 16px 0;}
        .notice.ok {background: #edf7ed; color: #216e39;}
        .notice.error {background: #fdeaea; color: #8a1f1f;}
        code {background: #f0f0f0; padding: 2px 6px; border-radius: 4px;}
        .btn {background: #2E7D32; color: white; border: none; padding: 12px 24px; border-radius: 10px; font-size: 1rem; cursor: pointer; text-decoration: none; display: inline-block;}
    </style>
</head>
<body>
<div class="container">
    <h1>🌲 Grid Tracking Install</h1>

    <?php if ($message): ?>
        <div class="notice ok"><?= $message ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="notice error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($step === '1'): ?>
        <p>This script will create the database tables needed for Grid Tracking (including <strong>username</strong> support for leaders instead of email) and insert a default organiser account.</p>
        <p><strong>Warning:</strong> Only run this on a fresh or empty database. It will not delete existing data but may add columns.</p>
        <form method="post" action="?step=2">
            <button type="submit" class="btn">Create Tables &amp; Default User</button>
        </form>
        <p style="margin-top:20px; font-size:0.9rem;">After success, <strong>delete this install.php file</strong> and login with the default credentials (change password right away!). You can then create more users via events.php or register.php.</p>

    <?php elseif ($step === 'done'): ?>
        <p>Setup complete! 🎉</p>
        <p><a href="admin.php" class="btn">Go to Admin Login</a></p>
        <p style="color:#c62828; font-weight:700;">DELETE install.php NOW for security.</p>
    <?php endif; ?>

    <hr style="margin:30px 0; border:none; border-top:1px solid #eee;">
    <p style="font-size:0.85rem; color:#666;">Default credentials (if created): <strong>username: admin</strong> / <strong>password: ChangeMe123!</strong><br>
    Examples for leaders: Bloory, Blossom, Richard — create them in Events &amp; Teams after logging in as organiser.</p>
</div>
</body>
</html>