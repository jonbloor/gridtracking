<?php
require __DIR__ . '/config.php';
$lastUpdated = date('d F Y');
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Your Privacy – Grid Tracking</title>
<link rel="icon" href="favicon.ico">
<style>
:root{--brand:#7413dc;--bg:#eef2f7;--card:#fff;--text:#1f2937;--muted:#5f6b76;--border:#d8dee6;--link:#1565C0;}
*{box-sizing:border-box;} body{margin:0;font-family:system-ui;background:var(--bg);color:var(--text);line-height:1.65;}
.wrap{max-width:820px;margin:0 auto;padding:22px;} .card{background:var(--card);border-radius:18px;box-shadow:0 8px 24px rgba(0,0,0,0.08);padding:28px;}
h1,h2{line-height:1.2;color:var(--brand);} .button{display:inline-flex;align-items:center;justify-content:center;padding:11px 15px;border-radius:10px;background:#fff;border:1px solid var(--border);color:var(--text);text-decoration:none;font-weight:600;margin:0 10px 18px 0;}
a{color:var(--link);} .box{background:#f6f3ff;border:1px solid #ddd0fa;border-radius:12px;padding:14px 16px;} ul{padding-left:22px;}
</style>
</head>
<body>
<div class="wrap">
<a class="button" href="index.html">Back to Grid Tracking</a>
<a class="button" href="privacy.php">Full privacy notice</a>
<div class="card">
<h1>Your privacy when using Grid Tracking</h1>
<p class="box">This page explains what happens to your information when you use Grid Tracking during a Scout activity.</p>
<ul>
<li>the app uses your team name;</li>
<li>your team PIN to check the update is from the right team;</li>
<li>your phone’s location;</li>
<li>the time you sent your update.</li>
</ul>
<p>It does not ask for your name or nickname, and it does not keep IP addresses in the location log.</p>
<p>Your location updates are normally kept for up to <strong>24 hours</strong> and then deleted. Leaders can also delete the logged updates when the hike or event is turned off.</p>
<p>Contact: <strong>[insert group email address]</strong></p>
</div>
</div>
</body>
</html>