<?php
require __DIR__ . '/config.php';

if (is_admin_logged_in()) {
    header('Location: admin.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();

    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $fullName = trim((string)($_POST['full_name'] ?? ''));

    if ($email === '' || $password === '') {
        $error = 'Email and password are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 10) {
        $error = 'Please use a password of at least 10 characters.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, full_name, is_organiser) VALUES (?, ?, ?, 1)');

        try {
            $stmt->execute([$email, $hash, mb_substr($fullName, 0, 100)]);
            $success = 'Account created. You can now sign in.';
        } catch (PDOException $e) {
            $error = 'That email address is already in use.';
        }
    }
}

$csrf = post_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register – Grid Tracking</title>
    <link rel="icon" href="favicon.ico">
    <style>
        body {font-family:system-ui; background:#f4f4f4; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; padding:20px;}
        .container {background:white; padding:40px; border-radius:16px; box-shadow:0 6px 20px rgba(0,0,0,0.1); max-width:420px; width:100%; color:#222;}
        h1 {margin-top:0; color:#2E7D32;}
        input {width:100%; padding:14px; margin:12px 0; font-size:1rem; border:2px solid #ddd; border-radius:10px; box-sizing:border-box;}
        button {background:#2E7D32; color:white; border:none; padding:16px; font-size:1rem; border-radius:10px; width:100%; cursor:pointer;}
        .notice {padding:12px 14px; border-radius:10px; margin:12px 0;}
        .notice.ok {background:#edf7ed; color:#216e39;}
        .notice.error {background:#fdeaea; color:#8a1f1f;}
    </style>
</head>
<body>
<div class="container">
    <h1>🌲 Register for Grid Tracking</h1>
    <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="notice ok"><?= h($success) ?></div><?php endif; ?>
    <form method="post" novalidate>
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="text" name="full_name" placeholder="Full name (optional)" maxlength="100">
        <input type="email" name="email" placeholder="Email" required autocomplete="username">
        <input type="password" name="password" placeholder="Password" required autocomplete="new-password">
        <button type="submit">Register as organiser</button>
    </form>
    <p style="text-align:center; margin-top:20px;"><a href="admin.php">Already have an account? Sign in</a></p>
</div>
</body>
</html>
