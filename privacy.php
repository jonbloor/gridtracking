<?php
require __DIR__ . '/config.php';
$lastUpdated = date('d F Y');
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Privacy Notice – Grid Tracking</title>
<link rel="icon" href="favicon.ico">
<style>
:root{--brand:#2E7D32;--bg:#f4f6f8;--card:#fff;--text:#1f2937;--muted:#5f6b76;--border:#d8dee6;--link:#1565C0;}
*{box-sizing:border-box;} body{margin:0;font-family:system-ui;background:var(--bg);color:var(--text);line-height:1.6;}
.wrap{max-width:900px;margin:0 auto;padding:22px;} .card{background:var(--card);border-radius:18px;box-shadow:0 8px 24px rgba(0,0,0,0.08);padding:28px;}
h1,h2{line-height:1.2;color:var(--brand);} h1{margin-top:0;font-size:2rem;} h2{margin-top:28px;font-size:1.3rem;}
.meta{color:var(--muted);margin-top:-8px;} .toplinks{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 18px;}
.button{display:inline-flex;align-items:center;justify-content:center;padding:11px 15px;border-radius:10px;background:#fff;border:1px solid var(--border);color:var(--text);text-decoration:none;font-weight:600;}
a{color:var(--link);} .note{background:#eef7f0;border:1px solid #cbe6d3;border-radius:12px;padding:14px 16px;} ul{padding-left:22px;}
</style>
</head>
<body>
<div class="wrap">
<div class="toplinks"><a class="button" href="index.html">Public page</a><a class="button" href="privacy-young-people.php">Young people’s privacy page</a><a class="button" href="admin.php">Admin</a></div>
<div class="card">
<h1>Privacy Notice for Grid Tracking</h1>
<p class="meta">Last updated: <?= h($lastUpdated) ?></p>
<div class="note">This notice explains how <strong>4th Ashby Scout Group</strong> uses personal data in the Grid Tracking app. Please replace the contact placeholders below before publishing.</div>
<h2>What this app collects</h2>
<ul>
<li>team name;</li>
<li>team PIN verification information;</li>
<li>location coordinates from the device;</li>
<li>the date and time of each location update;</li>
<li>adult leader account details used for the admin area.</li>
</ul>
<h2>What this app does not collect in the public logging flow</h2>
<ul>
<li>it does not ask for a scout name;</li>
<li>it does not ask for a nickname;</li>
<li>it does not store IP addresses in the location log.</li>
</ul>
<h2>Retention</h2>
<p>Location records are intended to be kept for up to <strong>24 hours</strong> and then deleted automatically. Leaders can also choose to delete logged locations when an event or hike is turned off.</p>
<h2>Contact</h2>
<p>Contact email: <strong>[insert group email address]</strong></p>
</div>
</div>
</body>
</html>