<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
session_start();
if (!($_SESSION['admin'] ?? false)) { header('Location: index.php'); exit; }

$db = getDB(); $msg = ''; $err = '';

// Ensure tables exist
try {
    $db->exec("CREATE TABLE IF NOT EXISTS coupons (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(30) NOT NULL UNIQUE,
        type ENUM('flat','percent') DEFAULT 'flat',
        value DECIMAL(10,2) NOT NULL,
        min_order DECIMAL(10,2) DEFAULT 0,
        max_discount DECIMAL(10,2) DEFAULT 0,
        usage_limit INT DEFAULT 0,
        used_count INT DEFAULT 0,
        per_user_limit INT DEFAULT 1,
        is_active TINYINT(1) DEFAULT 1,
        expires_at DATE DEFAULT NULL,
        description VARCHAR(255) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS coupon_usage (
        id INT AUTO_INCREMENT PRIMARY KEY,
        coupon_id INT NOT NULL,
        phone VARCHAR(20) NOT NULL,
        order_id INT NOT NULL,
        discount_amount DECIMAL(10,2) NOT NULL,
        used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch(Exception $e) { error_log("Coupon table: " . $e->getMessage()); }

if ($_POST['action'] ?? '' === 'add_coupon') {
    $code  = strtoupper(trim($_POST['code'] ?? ''));
    $type  = $_POST['type'] ?? 'flat';
    $val   = (float)($_POST['value'] ?? 0);
    $min   = (float)($_POST['min_order'] ?? 0);
    $max   = (float)($_POST['max_discount'] ?? 0);
    $limit = (int)($_POST['usage_limit'] ?? 0);
    $pul   = max(1, (int)($_POST['per_user_limit'] ?? 1));
    $exp   = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
    $desc  = trim($_POST['description'] ?? '');
    if ($code && $val > 0) {
        try {
            $db->prepare("INSERT INTO coupons (code,type,value,min_order,max_discount,usage_limit,per_user_limit,expires_at,description) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$code,$type,$val,$min,$max,$limit,$pul,$exp,$desc]);
            $msg = "Coupon '{$code}' add ho gaya!";
        } catch(Exception $e) { $err = "Yeh code pehle se hai ya error: " . $e->getMessage(); }
    } else { $err = "Code aur Value zaroori hain."; }
}
if (isset($_GET['toggle']) && isset($_GET['val'])) {
    $db->prepare("UPDATE coupons SET is_active=? WHERE id=?")->execute([(int)$_GET['val'],(int)$_GET['toggle']]);
    header("Location: coupons.php"); exit;
}
if (isset($_GET['delete'])) {
    $db->prepare("DELETE FROM coupons WHERE id=?")->execute([(int)$_GET['delete']]);
    header("Location: coupons.php"); exit;
}

try {
    $coupons = $db->query("SELECT c.*, COALESCE((SELECT COUNT(*) FROM coupon_usage cu WHERE cu.coupon_id=c.id),0) as total_used FROM coupons ORDER BY created_at DESC")->fetchAll();
} catch(Exception $e) {
    try { $coupons = $db->query("SELECT *, 0 as total_used FROM coupons ORDER BY created_at DESC")->fetchAll(); }
    catch(Exception $e2) { $coupons = []; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Coupons</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--green:#1db954;--green2:#17a349;--red:#e53935;--amber:#f59e0b;--blue:#3b82f6;--bg:#0f1117;--bg2:#181c25;--bg3:#1f2433;--border:rgba(255,255,255,.08);--text:#eef0f4;--muted:#7a8099;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg2);color:var(--text);}
.header{background:#fff;border-bottom:1px solid var(--border);padding:0 24px;display:flex;align-items:center;gap:14px;height:58px;position:sticky;top:0;z-index:100;box-shadow:0 1px 3px rgba(0,0,0,.06);}
.header-nav{margin-left:auto;display:flex;gap:6px;flex-wrap:wrap;}
.header-nav a{font-size:12px;font-weight:600;padding:6px 12px;border-radius:8px;text-decoration:none;color:var(--muted);}
.header-nav a.active,.header-nav a:hover{background:var(--green);color:#fff;}
.layout{display:grid;grid-template-columns:300px 1fr;min-height:calc(100vh - 58px);}
.sidebar{background:#fff;border-right:1px solid var(--border);padding:24px;}
.main{padding:24px;}
.flash{padding:11px 14px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:16px;}
.flash.s{background:#dcfce7;border:1px solid #86efac;color:#15803d;}
.flash.e{background:#fee2e2;border:1px solid #fca5a5;color:#b91c1c;}
.section-title{font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:12px;}
.form-card{background:#fff;border:1px solid var(--border);border-radius:12px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,.05);}
.form-card h3{font-size:13px;font-weight:700;margin-bottom:14px;color:var(--text);}
.field{margin-bottom:10px;}
.field label{display:block;font-size:11px;font-weight:700;color:var(--muted);margin-bottom:4px;text-transform:uppercase;}
.field input,.field select{width:100%;background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:9px 12px;color:var(--text);font-size:13px;font-family:inherit;outline:none;}
.field input:focus,.field select:focus{border-color:var(--green);}
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
.btn{display:inline-flex;align-items:center;gap:5px;padding:9px 16px;border-radius:8px;border:none;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .15s;}
.btn-green{background:var(--green);color:#fff;width:100%;justify-content:center;}
.btn-outline{background:#fff;border:1px solid var(--border);color:var(--muted);}
.btn-outline:hover{border-color:var(--green);color:var(--green);}
.coupon-grid{display:grid;gap:12px;}
.coupon-card{background:#fff;border:1px solid var(--border);border-radius:12px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,.05);position:relative;border-left:4px solid var(--green);}
.coupon-card.inactive{border-left-color:#d1d5db;opacity:.65;}
.coupon-code{font-size:20px;font-weight:800;letter-spacing:2px;color:var(--green);font-family:monospace;}
.coupon-card.inactive .coupon-code{color:var(--muted);}
.coupon-meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;}
.badge{display:inline-flex;align-items:center;padding:3px 9px;border-radius:20px;font-size:10px;font-weight:700;}
.b-green{background:#dcfce7;color:#16a34a;}
.b-amber{background:#fef3c7;color:#92400e;}
.b-red{background:#fee2e2;color:#b91c1c;}
.b-gray{background:#f3f4f6;color:#6b7280;}
.coupon-stats{font-size:12px;color:var(--muted);margin-top:8px;display:flex;gap:16px;}
.coupon-desc{font-size:12px;color:var(--muted);margin-top:5px;}
.coupon-actions{margin-top:12px;display:flex;gap:8px;}
.empty{text-align:center;padding:48px;color:var(--muted);}
@media(max-width:768px){.layout{grid-template-columns:1fr;}}
</style>
</head>
<body>
<div class="header">
  <span style="font-size:22px">🍽</span>
  <div><div style="font-size:15px;font-weight:700"><?= htmlspecialchars(getSetting('restaurant_name','Restaurant')) ?></div>
  <div style="font-size:11px;color:var(--muted)">Coupons</div></div>
  <nav class="header-nav">
    <a href="index.php">📋 Orders</a>
    <a href="menu.php">🍛 Menu</a>
    <a href="bills.php">🧾 Bills</a>
    <a href="coupons.php" class="active">🏷 Coupons</a>
    <a href="store-hours.php">🕐 Hours</a>
    <a href="settings.php">⚙️ Settings</a>
  </nav>
</div>
<div class="layout">
<aside class="sidebar">
  <?php if ($msg): ?><div class="flash s">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="flash e">❌ <?= htmlspecialchars($err) ?></div><?php endif; ?>
  <div class="section-title">Nawa Coupon</div>
  <div class="form-card">
    <form method="POST">
      <input type="hidden" name="action" value="add_coupon">
      <div class="field"><label>Code *</label><input type="text" name="code" placeholder="SAVE50" style="text-transform:uppercase" required></div>
      <div class="field-row">
        <div class="field"><label>Type</label>
          <select name="type"><option value="flat">Flat (₹)</option><option value="percent">Percent (%)</option></select>
        </div>
        <div class="field"><label>Value *</label><input type="number" name="value" placeholder="50" min="1" step="0.01" required></div>
      </div>
      <div class="field-row">
        <div class="field"><label>Min Order</label><input type="number" name="min_order" placeholder="200" min="0" value="0"></div>
        <div class="field"><label>Max Discount</label><input type="number" name="max_discount" placeholder="0=no limit" min="0" value="0"></div>
      </div>
      <div class="field-row">
        <div class="field"><label>Total Uses</label><input type="number" name="usage_limit" placeholder="0=unlimited" min="0" value="0"></div>
        <div class="field"><label>Per User</label><input type="number" name="per_user_limit" placeholder="1" min="1" value="1"></div>
      </div>
      <div class="field"><label>Expiry Date</label><input type="date" name="expires_at" min="<?= date('Y-m-d') ?>"></div>
      <div class="field"><label>Description</label><input type="text" name="description" placeholder="Welcome offer..."></div>
      <button type="submit" class="btn btn-green">➕ Coupon Banao</button>
    </form>
  </div>
</aside>
<main class="main">
  <div class="section-title" style="margin-bottom:14px">Saare Coupons (<?= count($coupons) ?>)</div>
  <div class="coupon-grid">
  <?php foreach ($coupons as $c):
    $active  = $c['is_active'] && (!$c['expires_at'] || $c['expires_at'] >= date('Y-m-d'));
    $expired = $c['expires_at'] && $c['expires_at'] < date('Y-m-d');
    $full    = $c['usage_limit'] > 0 && $c['used_count'] >= $c['usage_limit'];
  ?>
  <div class="coupon-card <?= !$c['is_active'] ? 'inactive' : '' ?>">
    <div style="display:flex;justify-content:space-between;align-items:flex-start">
      <div class="coupon-code"><?= htmlspecialchars($c['code']) ?></div>
      <div style="display:flex;gap:6px">
        <?php if ($expired): ?><span class="badge b-red">Expired</span>
        <?php elseif ($full): ?><span class="badge b-red">Full</span>
        <?php elseif ($c['is_active']): ?><span class="badge b-green">Active</span>
        <?php else: ?><span class="badge b-gray">Off</span><?php endif; ?>
      </div>
    </div>
    <?php if ($c['description']): ?><div class="coupon-desc"><?= htmlspecialchars($c['description']) ?></div><?php endif; ?>
    <div class="coupon-meta">
      <?php if ($c['type']==='flat'): ?>
        <span class="badge b-green">₹<?= number_format($c['value'],0) ?> Off</span>
      <?php else: ?>
        <span class="badge b-green"><?= $c['value'] ?>% Off<?= $c['max_discount']>0?' (max ₹'.$c['max_discount'].')':'' ?></span>
      <?php endif; ?>
      <?php if ($c['min_order']>0): ?><span class="badge b-amber">Min ₹<?= $c['min_order'] ?></span><?php endif; ?>
      <?php if ($c['expires_at']): ?><span class="badge b-gray">Exp: <?= date('d M', strtotime($c['expires_at'])) ?></span><?php endif; ?>
    </div>
    <div class="coupon-stats">
      <span>Used: <strong><?= $c['used_count'] ?><?= $c['usage_limit']>0?' / '.$c['usage_limit']:'' ?></strong></span>
      <span>Per user: <strong><?= $c['per_user_limit'] ?></strong></span>
    </div>
    <div class="coupon-actions">
      <?php if ($c['is_active']): ?>
        <a href="?toggle=<?= $c['id'] ?>&val=0" class="btn btn-outline" style="font-size:11px;padding:5px 10px">⛔ Disable</a>
      <?php else: ?>
        <a href="?toggle=<?= $c['id'] ?>&val=1" class="btn btn-outline" style="font-size:11px;padding:5px 10px;color:var(--green)">✅ Enable</a>
      <?php endif; ?>
      <a href="?delete=<?= $c['id'] ?>" class="btn btn-outline" style="font-size:11px;padding:5px 10px;color:var(--red)" onclick="return confirm('Delete?')">🗑 Delete</a>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if(empty($coupons)): ?>
  <div class="empty"><div style="font-size:40px;margin-bottom:12px">🏷</div><p>Koi coupon nahi. Upar form se banao!</p></div>
  <?php endif; ?>
  </div>
</main>
</div>
</body>
</html>
