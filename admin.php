<?php
require __DIR__ . '/config.php';

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: admin.php?logged_out=1');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    verify_csrf_token();

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } else {
        $stmt = $pdo->prepare('SELECT id, username, password_hash, full_name, is_organiser FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['is_admin'] = 1;
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['full_name'] = $user['full_name'] ?: $user['username'];
            $_SESSION['is_organiser'] = (int)$user['is_organiser'];
            header('Location: admin.php');
            exit;
        }

        $error = 'Invalid login details.';
    }
}

if (!is_admin_logged_in()) {
    $csrf = post_csrf_token();
    ?>
    <!DOCTYPE html>
    <html lang="en-GB">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin Login – Grid Tracking</title>
        <link rel="icon" href="favicon.ico">
        <style>
            body {font-family:system-ui; background:#f4f4f4; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; padding:20px; color:#222;}
            .container {background:white; padding:36px; border-radius:18px; box-shadow:0 6px 20px rgba(0,0,0,0.1); max-width:420px; width:100%;}
            h1 {margin-top:0; color:#2E7D32;}
            input {width:100%; padding:14px; margin:12px 0; font-size:1rem; border:2px solid #ddd; border-radius:10px; box-sizing:border-box;}
            button {background:#2E7D32; color:white; border:none; padding:14px; font-size:1rem; border-radius:10px; width:100%; cursor:pointer;}
            .error {background:#fdeaea; color:#8a1f1f; padding:12px; border-radius:10px; margin-bottom:16px;}
            .ok {background:#edf7ed; color:#216e39; padding:12px; border-radius:10px; margin-bottom:16px;}
            p.meta {color:#666; font-size:0.95rem;}
        </style>
    </head>
    <body>
    <div class="container">
        <h1>🌲 Grid Tracking Admin</h1>
        <p class="meta">Sign in to view the live map and manage events.</p>
        <?php if ($error !== ''): ?>
            <div class="error"><?= h($error) ?></div>
        <?php elseif (isset($_GET['logged_out'])): ?>
            <div class="ok">You have been logged out.</div>
        <?php endif; ?>
        <form method="post" novalidate>
            <input type="hidden" name="action" value="login">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <label>
                <span>Username</span>
                <input type="text" name="username" required autocomplete="username" placeholder="e.g. Bloory">
            </label>
            <label>
                <span>Password</span>
                <input type="password" name="password" required autocomplete="current-password">
            </label>
            <button type="submit">Sign in</button>
        </form>
        <p class="meta" style="margin-top:18px;">Need an account? Use <a href="register.php">register.php</a> for the first organiser, or create extra leader accounts from the events screen later. Or run install.php for initial setup.</p>
    </div>
    </body>
    </html>
    <?php
    exit;
}

$buildFiles = [
    __FILE__,
    __DIR__ . '/index.html',
    __DIR__ . '/api.php',
    __DIR__ . '/success.html',
    __DIR__ . '/sw.js',
    __DIR__ . '/site.webmanifest',
    __DIR__ . '/event-settings.php',
];

$buildTimestamp = 0;

foreach ($buildFiles as $file) {
    if (is_file($file)) {
        $buildTimestamp = max($buildTimestamp, filemtime($file));
    }
}

$build = $buildTimestamp > 0 ? date('d/m/Y H:i', $buildTimestamp) : 'unknown';
$activeEvent = get_active_event($pdo, current_user_id());
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">

    <title>Grid Tracking – Control Panel</title>

    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="192x192" href="android-chrome-192x192.png">
    <link rel="apple-touch-icon" href="android-chrome-192x192.png">
    <link rel="manifest" href="site.webmanifest">

    <meta name="theme-color" content="#2E7D32">

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

    <style>
        :root {
            --brand: #2E7D32;
            --surface: #ffffff;
            --bg: #f4f4f4;
            --text: #222;
            --muted: #5f6b76;
            --border: #e8e8e8;
            --danger: #c62828;
            --amber: #8a5a00;
            --ok: #216e39;
            --link: #1565C0;
        }

        * { box-sizing:border-box; }

        body { font-family:system-ui; margin:0; background:var(--bg); color:var(--text); }

        .header { background:var(--brand); color:#fff; padding:14px 16px; }
        .header-title { margin:0; font-size:1.25rem; line-height:1.2; }
        .header-meta { margin-top:4px; font-size:0.92rem; color:rgba(255,255,255,0.92); }

        .container { max-width:1120px; margin:0 auto; padding:14px 14px 30px; }

        .topbar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between; margin-bottom:14px; }
        .event-chip { background:var(--surface); border-radius:14px; padding:12px 14px; box-shadow:0 4px 12px rgba(0,0,0,0.08); flex:1 1 260px; }
        .event-chip .label { font-size:0.82rem; color:var(--muted); text-transform:uppercase; letter-spacing:0.04em; }
        .event-chip .value { font-size:1.15rem; font-weight:700; margin-top:2px; }
        .event-chip .sub { font-size:0.92rem; color:var(--muted); margin-top:2px; }

        .actions { display:flex; flex-wrap:wrap; gap:10px; }
        button, a.button { padding:12px 16px; border:none; border-radius:10px; cursor:pointer; font-size:0.98rem; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; min-height:44px; }
        .primary { background:#1565C0; color:white; }
        .secondary { background:#fff; color:#222; border:1px solid #ccc; }
        .logout { background:#6c757d; color:white; }

        .stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(145px, 1fr)); gap:12px; margin-bottom:14px; }
        .stat { background:var(--surface); padding:14px; border-radius:16px; box-shadow:0 4px 12px rgba(0,0,0,0.08); }
        .stat .label { font-size:0.92rem; color:var(--muted); margin-bottom:6px; }
        .stat .value { font-size:1.7rem; font-weight:700; line-height:1; }

        .attention-panel { background:#fff3cd; border:1px solid #ffe08a; color:#684f00; border-radius:16px; padding:14px; margin-bottom:14px; display:none; }
        .attention-panel h2 { margin:0 0 8px; font-size:1.05rem; }
        .attention-list { margin:0; padding-left:20px; }
        .attention-list li { margin:4px 0; }

        #map { height:46vh; min-height:300px; max-height:520px; border-radius:16px; margin:14px 0 10px; box-shadow:0 4px 12px rgba(0,0,0,0.1); }

        .timestamp { font-size:0.92rem; color:var(--muted); margin:0 0 14px; }
        .card { background:var(--surface); padding:14px; border-radius:16px; box-shadow:0 4px 12px rgba(0,0,0,0.08); }
        .card h2 { margin:0 0 10px; font-size:1.1rem; }

        .table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
        table { width:100%; border-collapse:collapse; min-width:680px; }
        th, td { padding:12px 10px; text-align:left; border-bottom:1px solid var(--border); vertical-align:middle; }
        th { background:#f8f8f8; font-size:0.92rem; }

        .dot { display:inline-block; width:14px; height:14px; border-radius:50%; margin-right:8px; vertical-align:middle; border:1px solid rgba(0,0,0,0.08); }
        .team-link { background:none; border:none; padding:0; margin:0; color:var(--link); font:inherit; font-weight:700; cursor:pointer; text-align:left; text-decoration:underline; min-height:0; display:inline; }
        .team-label { display:inline-flex; align-items:center; color:var(--text); font-weight:700; }
        .history-btn { background:#1565C0; color:#fff; border:none; padding:10px 14px; border-radius:10px; cursor:pointer; min-width:96px; min-height:44px; font-weight:600; }

        .muted { color:var(--muted); }
        .status-none { color:var(--muted); font-weight:700; }
        .status-ok { color:var(--ok); font-weight:700; }
        .status-amber { color:var(--amber); font-weight:700; }
        .status-stale { color:var(--danger); font-weight:800; }

        .history-panel { margin-top:14px; display:none; }
        .history-panel.show { display:block; }
        .history-head { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:10px; margin-bottom:12px; }
        .history-head h2 { margin:0; font-size:1.1rem; }
        .history-meta { font-size:0.92rem; color:var(--muted); }
        .ghost-btn { background:#fff; color:#222; border:1px solid #ccc; }

        @media (max-width: 640px) {
            .hide-mobile { display:none; }
            table { min-width:0; }
            th, td { padding:10px 8px; font-size:0.95rem; }
            .history-btn { min-width:84px; padding:10px 12px; }
            .header-title { font-size:1.15rem; }
            .header-meta { font-size:0.84rem; }
            .actions { width:100%; }
            .actions .button, .actions button { flex:1 1 calc(50% - 7px); }
        }
    </style>
</head>

<body>
<div class="header">
    <h1 class="header-title">🌲 Grid Tracking</h1>
    <div class="header-meta">Signed in as <?= h((string)($_SESSION['full_name'] ?? 'Admin')) ?> • Last updated <?= h($build) ?> • Version 2026-04-24-2</div>
</div>

<div class="container">
    <div class="topbar">
        <div class="event-chip">
            <?php if ($activeEvent): ?>
                <div class="label">Active event</div>
                <div class="value"><?= h($activeEvent['event_name']) ?></div>
                <div class="sub" id="eventSummary">Loading latest data…</div>
            <?php else: ?>
                <div class="label">Active event</div>
                <div class="value">No active event</div>
                <div class="sub">Turn one on in events.php</div>
            <?php endif; ?>
        </div>

        <div class="actions">
            <a href="events.php" class="button primary">Events &amp; Teams</a>
            <a href="event-settings.php" class="button secondary">Settings</a>
            <button type="button" id="refreshBtn" class="button secondary">Refresh</button>
            <a href="admin.php?logout=1" class="button logout">Logout</a>
        </div>
    </div>

    <div class="stats">
        <div class="stat"><div class="label">Teams</div><div class="value" id="statTeams">0</div></div>
        <div class="stat"><div class="label">Plotted</div><div class="value" id="statPlotted">0</div></div>
        <div class="stat"><div class="label">Needs attention</div><div class="value" id="statStale">0</div></div>
        <div class="stat"><div class="label">Updates</div><div class="value" id="statUpdates">0</div></div>
    </div>

    <div id="attentionPanel" class="attention-panel">
        <h2>Needs attention</h2>
        <ul id="attentionList" class="attention-list"></ul>
    </div>

    <div id="map"></div>

    <p class="timestamp" id="lastUpdated">Last updated: just now</p>

    <div class="card">
        <h2>Latest location per team</h2>
        <div class="table-wrap">
            <table id="list">
                <thead>
                    <tr>
                        <th>Team</th>
                        <th>Time</th>
                        <th>Status</th>
                        <th class="hide-mobile">Lat</th>
                        <th class="hide-mobile">Lng</th>
                        <th>Route</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <div class="card history-panel" id="historyPanel">
        <div class="history-head">
            <div>
                <h2 id="historyTitle">Team route</h2>
                <div class="history-meta" id="historyMeta">Select a team to load its recent points.</div>
            </div>
            <button type="button" class="ghost-btn" id="closeHistoryBtn">Hide route</button>
        </div>

        <div class="table-wrap">
            <table id="historyTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Time</th>
                        <th class="hide-mobile">Lat</th>
                        <th class="hide-mobile">Lng</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td colspan="4" class="muted">No team selected.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
const APP_CONFIG = {
    appVersion: '2026-04-24-2',
    rateLimitMinutes: 5,
    reminderMinutes: 10,
    staleMinutes: 10
};

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

const map = L.map('map').setView([52.7, -1.5], 10);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors',
    referrerPolicy: 'origin-when-cross-origin'
}).addTo(map);

let markers = [];
let historyLine = null;
let historyMarkers = [];
let latestBounds = [];

const historyPanel = document.getElementById('historyPanel');
const historyBody = document.querySelector('#historyTable tbody');
const historyMeta = document.getElementById('historyMeta');
const historyTitle = document.getElementById('historyTitle');
const closeHistoryBtn = document.getElementById('closeHistoryBtn');
const refreshBtn = document.getElementById('refreshBtn');
const attentionPanel = document.getElementById('attentionPanel');
const attentionList = document.getElementById('attentionList');

refreshBtn.addEventListener('click', () => loadData(true));
closeHistoryBtn.addEventListener('click', closeHistory);

function clearLatestMarkers() {
    markers.forEach((marker) => map.removeLayer(marker));
    markers = [];
    latestBounds = [];
}

function clearHistoryOverlays() {
    if (historyLine) {
        map.removeLayer(historyLine);
        historyLine = null;
    }

    historyMarkers.forEach((marker) => map.removeLayer(marker));
    historyMarkers = [];
}

function fitToLatestMarkers() {
    if (latestBounds.length > 0) {
        map.fitBounds(latestBounds, { padding: [30, 30] });
    }
}

function closeHistory() {
    clearHistoryOverlays();
    historyPanel.classList.remove('show');
    historyTitle.textContent = 'Team route';
    historyMeta.textContent = 'Select a team to load its recent points.';
    historyBody.innerHTML = '<tr><td colspan="4" class="muted">No team selected.</td></tr>';
    fitToLatestMarkers();
}

async function showHistory(teamName) {
    if (!teamName) return;

    historyPanel.classList.add('show');
    historyTitle.textContent = `${teamName} route`;
    historyMeta.textContent = 'Loading…';
    historyBody.innerHTML = '<tr><td colspan="4" class="muted">Loading history…</td></tr>';

    clearHistoryOverlays();

    try {
        const res = await fetch(`api.php?action=team_history&team=${encodeURIComponent(teamName)}`, { cache: 'no-store' });
        const data = await res.json();

        if (data.error) {
            historyMeta.textContent = data.error;
            historyBody.innerHTML = '<tr><td colspan="4" class="muted">Could not load history.</td></tr>';
            return;
        }

        const rows = Array.isArray(data.history) ? data.history : [];
        const points = [];
        const tableRows = [];

        rows.forEach((row, index) => {
            const hasCoords = row.lat !== null && row.lng !== null && row.lat !== '' && row.lng !== '';
            if (hasCoords) points.push([Number(row.lat), Number(row.lng)]);

            tableRows.push(`
                <tr>
                    <td>${rows.length - index}</td>
                    <td>${escapeHtml(row.time || '—')}</td>
                    <td class="hide-mobile">${hasCoords ? escapeHtml(String(row.lat)) : '—'}</td>
                    <td class="hide-mobile">${hasCoords ? escapeHtml(String(row.lng)) : '—'}</td>
                </tr>
            `);
        });

        historyMeta.textContent = `${data.event_name || 'Active event'} • ${rows.length} point${rows.length === 1 ? '' : 's'} in the last 24 hours`;
        historyBody.innerHTML = tableRows.length ? tableRows.join('') : '<tr><td colspan="4" class="muted">No history yet for this team.</td></tr>';

        if (points.length > 0) {
            const orderedPoints = [...points].reverse();

            historyLine = L.polyline(orderedPoints, {
                color: data.color || '#7413dc',
                weight: 4,
                opacity: 0.85
            }).addTo(map);

            orderedPoints.forEach((point, index) => {
                const marker = L.circleMarker(point, {
                    radius: index === orderedPoints.length - 1 ? 9 : 6,
                    fillColor: data.color || '#7413dc',
                    color: '#fff',
                    weight: 2,
                    fillOpacity: 0.95
                }).addTo(map);

                historyMarkers.push(marker);
            });

            const group = L.featureGroup([historyLine, ...historyMarkers]);
            map.fitBounds(group.getBounds(), { padding: [30, 30] });
        }
    } catch (error) {
        historyMeta.textContent = 'Could not load history.';
        historyBody.innerHTML = '<tr><td colspan="4" class="muted">Could not load history.</td></tr>';
        console.error(error);
    }
}

function statusForMinutes(minutesAgo) {
    if (minutesAgo === null || Number.isNaN(minutesAgo)) {
        return { label: 'No update yet', css: 'status-none' };
    }

    if (minutesAgo <= APP_CONFIG.rateLimitMinutes) {
        return { label: `${minutesAgo} min ago`, css: 'status-ok' };
    }

    if (minutesAgo < APP_CONFIG.staleMinutes) {
        return { label: `⚠️ ${minutesAgo} min ago`, css: 'status-amber' };
    }

    return { label: `🚨 ${minutesAgo} min ago`, css: 'status-stale' };
}

function renderAttentionPanel(staleTeams) {
    attentionList.innerHTML = '';

    if (!Array.isArray(staleTeams) || staleTeams.length === 0) {
        attentionPanel.style.display = 'none';
        return;
    }

    staleTeams.forEach((team) => {
        const li = document.createElement('li');
        li.textContent = team.has_location
            ? `${team.team} — last update ${team.minutes_ago} min ago`
            : `${team.team} — no update yet`;
        attentionList.appendChild(li);
    });

    attentionPanel.style.display = 'block';
}

async function loadData(keepCurrentView = false) {
    try {
        const res = await fetch('api.php?action=admin', { cache: 'no-store' });
        const data = await res.json();

        if (data.error) {
            const eventSummary = document.getElementById('eventSummary');
            if (eventSummary) eventSummary.textContent = data.error;
            return;
        }

        if (data.settings) {
            APP_CONFIG.rateLimitMinutes = Number(data.settings.rate_limit_minutes || APP_CONFIG.rateLimitMinutes);
            APP_CONFIG.reminderMinutes = Number(data.settings.reminder_minutes || APP_CONFIG.reminderMinutes);
            APP_CONFIG.staleMinutes = Number(data.settings.stale_minutes || APP_CONFIG.staleMinutes);
        }

        clearLatestMarkers();

        document.getElementById('statTeams').textContent = String(data.stats?.team_count ?? 0);
        document.getElementById('statPlotted').textContent = String(data.stats?.teams_plotted ?? 0);
        document.getElementById('statStale').textContent = String(data.stats?.teams_stale ?? 0);
        document.getElementById('statUpdates').textContent = String(data.stats?.total_updates ?? 0);

        renderAttentionPanel(data.stale_teams || []);

        const tbody = document.querySelector('#list tbody');
        tbody.innerHTML = '';

        for (const loc of data.locations || []) {
            const color = loc.color || '#7413dc';
            const teamName = String(loc.team || '');
            const hasCoords = loc.lat !== null && loc.lng !== null && loc.lat !== '' && loc.lng !== '';
            const historyCount = Number(loc.history_count || 0);
            const canOpenHistory = hasCoords && historyCount >= 1;
            const minutesAgo = loc.minutes_ago !== null ? Number(loc.minutes_ago) : null;
            const status = statusForMinutes(minutesAgo);

            if (hasCoords) {
                const marker = L.circleMarker([Number(loc.lat), Number(loc.lng)], {
                    radius: 10,
                    fillColor: color,
                    color: '#fff',
                    weight: 3,
                    fillOpacity: 0.9
                }).addTo(map).bindPopup(
                    `<strong>${escapeHtml(teamName)}</strong><br>${escapeHtml(loc.time || '')}`
                );

                markers.push(marker);
                latestBounds.push([Number(loc.lat), Number(loc.lng)]);
            }

            const teamCell = canOpenHistory
                ? `<button type="button" class="team-link" data-team="${escapeHtml(teamName)}"><span class="dot" style="background:${escapeHtml(color)};"></span>${escapeHtml(teamName || '—')}</button>`
                : `<span class="team-label"><span class="dot" style="background:${escapeHtml(color)};"></span>${escapeHtml(teamName || '—')}</span>`;

            const routeCell = canOpenHistory
                ? `<button type="button" class="history-btn" data-team="${escapeHtml(teamName)}">${historyCount >= 2 ? 'Route' : 'History'}</button>`
                : `<span class="muted">No updates yet</span>`;

            tbody.insertAdjacentHTML('beforeend', `
                <tr>
                    <td>${teamCell}</td>
                    <td>${escapeHtml(loc.time || 'No update yet')}</td>
                    <td class="${status.css}">${escapeHtml(status.label)}</td>
                    <td class="hide-mobile">${hasCoords ? escapeHtml(String(loc.lat)) : '—'}</td>
                    <td class="hide-mobile">${hasCoords ? escapeHtml(String(loc.lng)) : '—'}</td>
                    <td>${routeCell}</td>
                </tr>
            `);
        }

        if ((data.locations || []).length === 0) {
            tbody.innerHTML = '<tr><td colspan="6">No teams found for the active event.</td></tr>';
        }

        tbody.querySelectorAll('[data-team]').forEach((el) => {
            el.addEventListener('click', () => showHistory(el.getAttribute('data-team') || ''));
        });

        if (!keepCurrentView) fitToLatestMarkers();

        const eventSummaryEl = document.getElementById('eventSummary');
        if (eventSummaryEl) {
            eventSummaryEl.textContent = data.event_name
                ? `${data.stats?.teams_plotted ?? 0} plotted • ${data.stats?.teams_stale ?? 0} need attention • stale after ${APP_CONFIG.staleMinutes} min`
                : 'No active event at present.';
        }

        document.getElementById('lastUpdated').textContent = `Last updated: ${new Date().toLocaleString('en-GB')}`;
    } catch (error) {
        const eventSummaryEl = document.getElementById('eventSummary');
        if (eventSummaryEl) eventSummaryEl.textContent = 'Could not load the latest dashboard data.';
        console.error(error);
    }
}

loadData();
setInterval(() => loadData(true), 30000);

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js?v=' + encodeURIComponent(APP_CONFIG.appVersion)).catch(() => {});
}

let deferredPrompt;

window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;

    const installBtn = document.createElement('button');
    installBtn.textContent = '📲 Install Grid Tracking on homescreen';
    installBtn.style.cssText = 'margin: 20px auto 0; display: block; padding: 14px 24px; background: #1565C0; color: white; border: none; border-radius: 12px; font-size: 1rem; cursor: pointer; box-shadow: 0 4px 12px rgba(0,0,0,0.15);';
    document.querySelector('.container').appendChild(installBtn);

    installBtn.addEventListener('click', () => {
        if (deferredPrompt) {
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(() => {
                deferredPrompt = null;
                installBtn.remove();
            });
        }
    });
});
</script>
</body>
</html>