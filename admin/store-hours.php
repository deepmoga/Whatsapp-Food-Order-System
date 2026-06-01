<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
session_start();
if (!($_SESSION['admin'] ?? false)) { header('Location: index.php'); exit; }

$db  = getDB();
$msg = '';

// Ensure tables exist
try {
    $db->exec("CREATE TABLE IF NOT EXISTS store_schedule (
        id INT AUTO_INCREMENT PRIMARY KEY,
        day_of_week TINYINT NOT NULL,
        day_name VARCHAR(15) NOT NULL,
        is_open TINYINT(1) DEFAULT 1,
        open_time TIME DEFAULT '10:00:00',
        close_time TIME DEFAULT '22:00:00'
    )");
    // Insert defaults if empty
    $count = $db->query("SELECT COUNT(*) FROM store_schedule")->fetchColumn();
    if ($count == 0) {
        $days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        foreach ($days as $i => $day) {
            $db->prepare("INSERT INTO store_schedule (day_of_week, day_name, is_open, open_time, close_time) VALUES (?,?,1,'10:00:00','22:00:00')")
               ->execute([$i, $day]);
        }
    }
} catch(Exception $e) { error_log($e->getMessage()); }

// AJAX: Toggle store on/off instantly
if (isset($_GET['ajax']) && $_GET['ajax'] === 'toggle_store') {
    $val = $_GET['val'] === '1' ? '1' : '0';
    $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('store_open',?) ON DUPLICATE KEY UPDATE setting_value=?")
       ->execute([$val, $val]);
    echo json_encode(['ok' => true, 'open' => $val === '1']);
    exit;
}

// Save schedule
if ($_POST['action'] ?? '' === 'save_schedule') {
    for ($d = 0; $d <= 6; $d++) {
        $isOpen    = isset($_POST["day_{$d}_open"]) ? 1 : 0;
        $openTime  = $_POST["day_{$d}_from"] ?? '10:00';
        $closeTime = $_POST["day_{$d}_to"]   ?? '22:00';
        $db->prepare("UPDATE store_schedule SET is_open=?, open_time=?, close_time=? WHERE day_of_week=?")
           ->execute([$isOpen, $openTime.':00', $closeTime.':00', $d]);
    }
    // Save closed message
    $closedMsg = trim($_POST['store_closed_message'] ?? '');
    $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('store_closed_message',?) ON DUPLICATE KEY UPDATE setting_value=?")
       ->execute([$closedMsg, $closedMsg]);
    $msg = 'saved';
}

// Fetch data
$schedule    = $db->query("SELECT * FROM store_schedule ORDER BY day_of_week")->fetchAll();
$storeOpen   = getSetting('store_open', '1') === '1';
$closedMsg   = getSetting('store_closed_message', '');
$storeStatus = isStoreOpen();

$dayEmojis = ['☀️','🌙','🌟','⭐','💫','✨','🎉'];
$punjabi   = ['Itvaar','Somvaar','Mangalvaar','Budhvaar','Veerevaar','Shukarvaar','Shanivaar'];
?>
<!DOCTYPE html>
<html lang="pa">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Store Hours</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--green:#1db954;--green2:#17a349;--red:#e53935;--amber:#f59e0b;--blue:#3b82f6;--bg:#0f1117;--bg2:#181c25;--bg3:#1f2433;--border:rgba(255,255,255,.08);--text:#eef0f4;--muted:#7a8099;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg2);color:var(--text);min-height:100vh;}

/* HEADER */
.header{background:#fff;border-bottom:1px solid var(--border);box-shadow:0 1px 3px rgba(0,0,0,.06);padding:0 24px;display:flex;align-items:center;gap:14px;height:58px;position:sticky;top:0;z-index:100;}
.header-nav{margin-left:auto;display:flex;gap:6px;flex-wrap:wrap;}
.header-nav a{font-size:12px;font-weight:600;padding:6px 12px;border-radius:8px;text-decoration:none;color:var(--muted);}
.header-nav a.active,.header-nav a:hover{background:var(--green);color:#fff;}

/* MAIN TOGGLE — Big store on/off */
.store-toggle-card{margin:20px 24px;background:var(--bg2);border:2px solid var(--border);border-radius:20px;padding:28px;display:flex;align-items:center;gap:24px;transition:border-color .3s;}
.store-toggle-card.open-state{border-color:rgba(29,185,84,.4);background:rgba(29,185,84,.05);}
.store-toggle-card.closed-state{border-color:rgba(229,57,53,.3);background:rgba(229,57,53,.04);}
.store-icon{font-size:48px;flex-shrink:0;}
.store-info{flex:1;}
.store-status-label{font-size:22px;font-weight:800;}
.store-status-label.open{color:var(--green);}
.store-status-label.closed{color:#ef5350;}
.store-sub{font-size:13px;color:var(--muted);margin-top:4px;}
.store-time{font-size:12px;color:var(--green);margin-top:6px;font-weight:600;}

/* BIG TOGGLE SWITCH */
.big-toggle{position:relative;width:72px;height:40px;flex-shrink:0;}
.big-toggle input{opacity:0;width:0;height:0;}
.big-slider{position:absolute;inset:0;background:#2d2d2d;border:2px solid var(--border);border-radius:40px;cursor:pointer;transition:.3s;}
.big-slider:before{content:'';position:absolute;width:30px;height:30px;left:3px;top:3px;background:#555;border-radius:50%;transition:.3s;display:flex;align-items:center;justify-content:center;}
.big-toggle input:checked+.big-slider{background:var(--green);border-color:var(--green);}
.big-toggle input:checked+.big-slider:before{transform:translateX(32px);background:#fff;}
.big-toggle input:not(:checked)+.big-slider{background:#3d1515;border-color:rgba(229,57,53,.4);}
.big-toggle input:not(:checked)+.big-slider:before{background:#ef5350;}

/* STATUS DOT */
.status-dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:6px;}
.dot-open{background:var(--green);box-shadow:0 0 8px var(--green);}
.dot-closed{background:#ef5350;box-shadow:0 0 8px #ef5350;}

.page{max-width:720px;margin:0 auto;padding:0 24px 40px;}

/* SCHEDULE TABLE */
.schedule-card{background:#fff;border:1px solid var(--border);border-radius:16px;overflow:hidden;margin-bottom:16px;}
.schedule-head{padding:16px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
.schedule-head h3{font-size:14px;font-weight:700;}
.schedule-head .hint{font-size:11px;color:var(--muted);}

.day-row{display:flex;align-items:center;gap:14px;padding:14px 22px;border-bottom:1px solid #f8fafc;transition:background .2s;}
.day-row:last-child{border-bottom:none;}
.day-row:hover{background:#fafafa;}
.day-row.closed-day{opacity:.5;}

.day-name{width:130px;flex-shrink:0;}
.day-name .en{font-size:13px;font-weight:700;}
.day-name .pa{font-size:11px;color:var(--muted);margin-top:2px;}

/* Small toggle */
.s-toggle{position:relative;width:42px;height:24px;flex-shrink:0;}
.s-toggle input{opacity:0;width:0;height:0;}
.s-slider{position:absolute;inset:0;background:var(--bg2);border:1px solid var(--border);border-radius:24px;cursor:pointer;transition:.3s;}
.s-slider:before{content:'';position:absolute;width:16px;height:16px;left:3px;top:3px;background:var(--muted);border-radius:50%;transition:.3s;}
.s-toggle input:checked+.s-slider{background:var(--green);border-color:var(--green);}
.s-toggle input:checked+.s-slider:before{transform:translateX(18px);background:#fff;}

.time-group{display:flex;align-items:center;gap:8px;flex:1;}
.time-label{font-size:11px;color:var(--muted);white-space:nowrap;}
.time-input{background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:7px 10px;color:var(--text);font-size:13px;font-family:inherit;outline:none;width:110px;}
.time-input:focus{border-color:var(--green);}
.time-input:disabled{opacity:.3;cursor:not-allowed;}
.dash{color:var(--muted);font-size:14px;}

/* Today highlight */
.day-row.today{background:rgba(29,185,84,.06);border-left:3px solid var(--green);}
.today-badge{font-size:10px;font-weight:700;background:rgba(29,185,84,.2);color:var(--green);padding:2px 8px;border-radius:10px;margin-left:8px;}

/* Closed message */
.msg-card{background:#fff;border:1px solid var(--border);border-radius:16px;padding:22px;margin-bottom:16px;}
.msg-card h3{font-size:14px;font-weight:700;margin-bottom:12px;}
.msg-card textarea{width:100%;background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:12px;color:var(--text);font-size:13px;font-family:inherit;outline:none;resize:vertical;min-height:120px;line-height:1.6;}
.msg-card textarea:focus{border-color:var(--green);}
.msg-card small{font-size:11px;color:var(--muted);margin-top:6px;display:block;}

/* Flash */
.flash{padding:12px 16px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:16px;}
.flash.success{background:rgba(29,185,84,.12);border:1px solid rgba(29,185,84,.3);color:var(--green);}

/* Save button */
.save-bar{position:sticky;bottom:0;background:var(--bg2);border-top:1px solid var(--border);padding:14px 24px;display:flex;align-items:center;gap:14px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 22px;border-radius:8px;border:none;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;}
.btn-green{background:var(--green);color:#fff;}
.btn-green:hover{background:var(--green2);}

/* Toast */
.toast{position:fixed;bottom:80px;right:20px;background:var(--bg2);border:1px solid rgba(29,185,84,.3);border-radius:10px;padding:11px 16px;font-size:13px;font-weight:600;color:var(--green);z-index:999;transform:translateY(60px);opacity:0;transition:all .3s;}
.toast.show{transform:translateY(0);opacity:1;}

@media(max-width:600px){
    .time-group{flex-wrap:wrap;}
    .day-row{flex-wrap:wrap;gap:10px;}
    .day-name{width:auto;}
}
</style>
</head>
<body>

<div class="header">
  <span style="font-size:22px">🍽</span>
  <div><div style="font-size:15px;font-weight:700"><?= htmlspecialchars(getSetting('restaurant_name','Restaurant')) ?></div>
  <div style="font-size:11px;color:var(--muted)">Store Hours</div></div>
  <nav class="header-nav">
    <a href="index.php">📋 Orders</a>
    <a href="menu.php">🍛 Menu</a>
    <a href="bills.php">🧾 Bills</a>
    <a href="coupons.php">🏷 Coupons</a>
    <a href="store-hours.php" class="active">🕐 Hours</a>
    <a href="settings.php">⚙️ Settings</a>
  </nav>
</div>

<!-- BIG STORE TOGGLE -->
<div class="store-toggle-card <?= $storeOpen ? 'open-state' : 'closed-state' ?>" id="storeCard">
  <div class="store-icon"><?= $storeOpen ? '🟢' : '🔴' ?></div>
  <div class="store-info">
    <div class="store-status-label <?= $storeOpen ? 'open' : 'closed' ?>" id="storeLabel">
      <span class="status-dot <?= $storeOpen ? 'dot-open' : 'dot-closed' ?>" id="statusDot"></span>
      <?= $storeOpen ? 'Store OPEN hai' : 'Store BAND hai' ?>
    </div>
    <div class="store-sub" id="storeSub">
      <?php if ($storeOpen && $storeStatus['open']): ?>
        Customers order kar sakte hain
      <?php elseif ($storeOpen && !$storeStatus['open']): ?>
        Manual: Open | Schedule: Band — schedule ke mutabik band hai
      <?php else: ?>
        Customers nu "band hai" message jaayega
      <?php endif; ?>
    </div>
    <?php
    $todayDay = (int)date('w');
    foreach ($schedule as $s) {
        if ($s['day_of_week'] == $todayDay && $s['is_open']) {
            echo '<div class="store-time">Aaj: ' . date('h:i A', strtotime($s['open_time'])) . ' — ' . date('h:i A', strtotime($s['close_time'])) . '</div>';
        }
    }
    ?>
  </div>
  <label class="big-toggle">
    <input type="checkbox" id="storeMasterToggle" <?= $storeOpen ? 'checked' : '' ?> onchange="toggleStore(this.checked)">
    <span class="big-slider"></span>
  </label>
</div>

<div class="page">
<?php if ($msg === 'saved'): ?>
<div class="flash success">✅ Schedule save ho gaya! Changes turant apply ho gaye.</div>
<?php endif; ?>

<form method="POST">
<input type="hidden" name="action" value="save_schedule">

<!-- WEEKLY SCHEDULE -->
<div class="schedule-card">
  <div class="schedule-head">
    <h3>📅 Weekly Schedule</h3>
    <span class="hint">Har din alag time set karo</span>
  </div>

  <?php
  $today = (int)date('w');
  foreach ($schedule as $s):
    $d       = $s['day_of_week'];
    $isToday = ($d === $today);
    $openT   = date('H:i', strtotime($s['open_time']));
    $closeT  = date('H:i', strtotime($s['close_time']));
    $isDayOpen = (bool)$s['is_open'];
  ?>
  <div class="day-row <?= !$isDayOpen ? 'closed-day' : '' ?> <?= $isToday ? 'today' : '' ?>" id="dayrow_<?= $d ?>">

    <div class="day-name">
      <div class="en">
        <?= $dayEmojis[$d] ?> <?= $s['day_name'] ?>
        <?php if ($isToday): ?><span class="today-badge">TODAY</span><?php endif; ?>
      </div>
      <div class="pa"><?= $punjabi[$d] ?></div>
    </div>

    <label class="s-toggle">
      <input type="checkbox" name="day_<?= $d ?>_open" value="1"
             <?= $isDayOpen ? 'checked' : '' ?>
             onchange="toggleDay(<?= $d ?>, this.checked)">
      <span class="s-slider"></span>
    </label>

    <div class="time-group" id="times_<?= $d ?>">
      <span class="time-label">Khulda:</span>
      <input type="time" class="time-input" name="day_<?= $d ?>_from"
             value="<?= $openT ?>" <?= !$isDayOpen ? 'disabled' : '' ?>>
      <span class="dash">—</span>
      <span class="time-label">Banda:</span>
      <input type="time" class="time-input" name="day_<?= $d ?>_to"
             value="<?= $closeT ?>" <?= !$isDayOpen ? 'disabled' : '' ?>>
    </div>

  </div>
  <?php endforeach; ?>
</div>

<!-- CLOSED MESSAGE -->
<div class="msg-card">
  <h3>💬 Store Closed Message</h3>
  <textarea name="store_closed_message" placeholder="Maafi karo ji, abhi restaurant band hai..."><?= htmlspecialchars($closedMsg) ?></textarea>
  <small>Yeh message customer nu jaayega jab store band hoga ya schedule se bahar order kare.</small>
</div>

<!-- SAVE -->
<div class="save-bar">
  <button type="submit" class="btn btn-green">💾 Schedule Save Karo</button>
  <span style="font-size:12px;color:var(--muted)">Save karne baad turant apply hoga</span>
</div>

</form>
</div>

<div class="toast" id="toast"></div>

<script>
// Big store toggle
async function toggleStore(isOpen) {
    const card  = document.getElementById('storeCard');
    const label = document.getElementById('storeLabel');
    const sub   = document.getElementById('storeSub');
    const dot   = document.getElementById('statusDot');

    try {
        const res  = await fetch('store-hours.php?ajax=toggle_store&val=' + (isOpen ? '1' : '0'));
        const data = await res.json();

        if (data.ok) {
            card.className  = 'store-toggle-card ' + (isOpen ? 'open-state' : 'closed-state');
            dot.className   = 'status-dot ' + (isOpen ? 'dot-open' : 'dot-closed');
            label.innerHTML = '<span class="status-dot ' + (isOpen ? 'dot-open' : 'dot-closed') + '"></span> ' +
                              (isOpen ? 'Store OPEN hai' : 'Store BAND hai');
            sub.textContent = isOpen ? 'Customers order kar sakte hain' : 'Customers nu "band hai" message jaayega';
            showToast(isOpen ? '✅ Store khul gaya!' : '🔴 Store band ho gaya!');
        }
    } catch(e) {
        showToast('❌ Error — dobara try karo');
    }
}

// Day toggle — enable/disable time inputs
function toggleDay(dayNum, isOpen) {
    const row    = document.getElementById('dayrow_' + dayNum);
    const inputs = document.querySelectorAll('#times_' + dayNum + ' input');
    row.classList.toggle('closed-day', !isOpen);
    inputs.forEach(inp => inp.disabled = !isOpen);
}

function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2500);
}
</script>
</body>
</html>    // Ensure table exists + add UNIQUE key to prevent duplicates
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS store_schedule (
            id INT AUTO_INCREMENT PRIMARY KEY,
            day_of_week TINYINT NOT NULL UNIQUE,
            day_name VARCHAR(15) NOT NULL,
            is_open TINYINT(1) DEFAULT 1,
            open_time TIME DEFAULT '10:00:00',
            close_time TIME DEFAULT '22:00:00'
        )");
        // Try adding unique constraint if table already exists without it
        try { $db->exec("ALTER TABLE store_schedule ADD UNIQUE KEY uq_day (day_of_week)"); } catch(Exception $e2){}

        // Clean duplicates first (keep lowest id per day)
        $db->exec("DELETE s1 FROM store_schedule s1
                   INNER JOIN store_schedule s2
                   WHERE s1.day_of_week = s2.day_of_week AND s1.id > s2.id");

        // Insert defaults only for missing days
        $days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        foreach ($days as $i => $day) {
            $db->prepare("INSERT IGNORE INTO store_schedule (day_of_week, day_name, is_open, open_time, close_time) VALUES (?,?,1,'10:00:00','22:00:00')")
               ->execute([$i, $day]);
        }
    } catch(Exception $e) { error_log("Schedule setup: " . $e->getMessage()); }

// AJAX: Toggle store on/off instantly
if (isset($_GET['ajax']) && $_GET['ajax'] === 'toggle_store') {
    $val = $_GET['val'] === '1' ? '1' : '0';
    $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('store_open',?) ON DUPLICATE KEY UPDATE setting_value=?")
       ->execute([$val, $val]);
    echo json_encode(['ok' => true, 'open' => $val === '1']);
    exit;
}

// Save schedule
if ($_POST['action'] ?? '' === 'save_schedule') {
    for ($d = 0; $d <= 6; $d++) {
        $isOpen    = isset($_POST["day_{$d}_open"]) ? 1 : 0;
        $openTime  = $_POST["day_{$d}_from"] ?? '10:00';
        $closeTime = $_POST["day_{$d}_to"]   ?? '22:00';
        $db->prepare("UPDATE store_schedule SET is_open=?, open_time=?, close_time=? WHERE day_of_week=?")
           ->execute([$isOpen, $openTime.':00', $closeTime.':00', $d]);
    }
    // Save closed message
    $closedMsg = trim($_POST['store_closed_message'] ?? '');
    $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('store_closed_message',?) ON DUPLICATE KEY UPDATE setting_value=?")
       ->execute([$closedMsg, $closedMsg]);
    $msg = 'saved';
}

// Fetch data
$schedule    = $db->query("SELECT * FROM store_schedule ORDER BY day_of_week")->fetchAll();
$storeOpen   = getSetting('store_open', '1') === '1';
$closedMsg   = getSetting('store_closed_message', '');
$storeStatus = isStoreOpen();

$dayEmojis = ['☀️','🌙','🌟','⭐','💫','✨','🎉'];
$punjabi   = ['Itvaar','Somvaar','Mangalvaar','Budhvaar','Veerevaar','Shukarvaar','Shanivaar'];
?>
