<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';

session_start();
$adminPass = 'admin123';
if ($_POST['pass'] ?? '' === $adminPass) $_SESSION['admin'] = true;
if (!($_SESSION['admin'] ?? false)) {
    header('Location: index.php'); exit;
}

$db = getDB();

// Ensure table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS delivery_boys (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        whatsapp_number VARCHAR(20) NOT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch(Exception $e) {}

$msg = ''; $msgType = 'success';
$action = ($_POST['action'] ?? '');

// ---- ADD ----
if ($action === 'add') {
    $name   = trim($_POST['name'] ?? '');
    $number = preg_replace('/\D/', '', trim($_POST['whatsapp_number'] ?? ''));
    if ($name && strlen($number) >= 10) {
        $db->prepare("INSERT INTO delivery_boys (name, whatsapp_number) VALUES (?,?)")
           ->execute([$name, $number]);
        $msg = "✅ {$name} add ho gaya!";
    } else {
        $msg = '❌ Naam aur WhatsApp number zaroori hain (country code sahit, e.g. 919XXXXXXXXX)';
        $msgType = 'error';
    }
}

// ---- EDIT ----
if ($action === 'edit') {
    $id     = (int)($_POST['id'] ?? 0);
    $name   = trim($_POST['name'] ?? '');
    $number = preg_replace('/\D/', '', trim($_POST['whatsapp_number'] ?? ''));
    if ($id && $name && strlen($number) >= 10) {
        $db->prepare("UPDATE delivery_boys SET name=?, whatsapp_number=? WHERE id=?")
           ->execute([$name, $number, $id]);
        $msg = "✅ Update ho gaya!";
    } else {
        $msg = '❌ Naam aur number check karo'; $msgType = 'error';
    }
}

// ---- TOGGLE ACTIVE ----
if ($action === 'toggle') {
    $id  = (int)($_POST['id'] ?? 0);
    $val = (int)($_POST['is_active'] ?? 0);
    $db->prepare("UPDATE delivery_boys SET is_active=? WHERE id=?")->execute([$val, $id]);
    $msg = $val ? "✅ Active kar dita" : "⏸ Inactive kar dita";
}

// ---- DELETE ----
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    try { $db->prepare("UPDATE orders SET delivery_boy_id=NULL WHERE delivery_boy_id=?")->execute([$id]); } catch(Exception $e) {}
    $db->prepare("DELETE FROM delivery_boys WHERE id=?")->execute([$id]);
    $msg = "🗑 Delete ho gaya";
}

$boys = $db->query("SELECT * FROM delivery_boys ORDER BY is_active DESC, name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="pa">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Delivery Boys — <?= htmlspecialchars(getSetting('restaurant_name','Restaurant')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--green:#1db954;--green2:#17a349;--red:#e53935;--blue:#3b82f6;--bg:#0f1117;--bg2:#181c25;--border:rgba(255,255,255,.08);--text:#eef0f4;--muted:#7a8099;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;background:#f3f4f6;min-height:100vh;}
.header{background:#fff;border-bottom:1px solid #e5e7eb;padding:0 16px;display:flex;align-items:center;gap:10px;height:58px;position:sticky;top:0;z-index:100;}
.header-title{font-size:15px;font-weight:700;color:#111;}
.header-nav{margin-left:auto;display:flex;gap:4px;overflow-x:auto;}
.header-nav a{font-size:12px;font-weight:600;padding:6px 10px;border-radius:8px;text-decoration:none;color:#6b7280;white-space:nowrap;}
.header-nav a:hover{background:#f3f4f6;color:#111;}
.header-nav a.active{background:var(--green);color:#fff;}
.container{max-width:760px;margin:24px auto;padding:0 16px;}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:20px;margin-bottom:20px;}
.card-title{font-size:15px;font-weight:700;color:#111;margin-bottom:16px;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;}
@media(max-width:500px){.form-row{grid-template-columns:1fr;}}
label{font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:4px;}
input[type=text]{width:100%;border:1px solid #e5e7eb;border-radius:8px;padding:9px 12px;font-size:13px;font-family:inherit;outline:none;color:#111;}
input[type=text]:focus{border-color:var(--green);}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:8px;border:none;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;}
.btn-green{background:var(--green);color:#fff;} .btn-green:hover{background:var(--green2);}
.btn-red{background:#fee2e2;color:#dc2626;border:1px solid #fecaca;} .btn-red:hover{background:#fca5a5;}
.btn-outline{background:#fff;border:1px solid #e5e7eb;color:#6b7280;}
.msg{padding:10px 14px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:16px;}
.msg.success{background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;}
.msg.error{background:#fee2e2;color:#dc2626;border:1px solid #fecaca;}
table{width:100%;border-collapse:collapse;}
th{background:#f8fafc;padding:10px 12px;text-align:left;font-size:10px;font-weight:700;color:#6b7280;letter-spacing:1px;text-transform:uppercase;border-bottom:1px solid #e5e7eb;}
td{padding:12px 12px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#111;vertical-align:middle;}
tr:last-child td{border-bottom:none;}
.badge-active{background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;padding:3px 9px;border-radius:20px;font-size:10px;font-weight:700;}
.badge-inactive{background:#f1f5f9;color:#64748b;border:1px solid #e2e8f0;padding:3px 9px;border-radius:20px;font-size:10px;font-weight:700;}
.actions{display:flex;gap:6px;flex-wrap:wrap;}
.hint{font-size:11px;color:#9ca3af;margin-top:4px;}
.empty{text-align:center;padding:40px;color:#9ca3af;}
.edit-form{display:none;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:14px;margin-top:10px;}
</style>
</head>
<body>

<div class="header">
  <span style="font-size:22px">🛵</span>
  <div><div class="header-title">Delivery Boys</div></div>
  <nav class="header-nav">
    <a href="index.php">📋 Orders</a>
    <a href="menu.php">🍛 Menu</a>
    <a href="bills.php">🧾 Bills</a>
    <a href="store-hours.php">🕐 Hours</a>
    <a href="coupons.php">🏷 Coupons</a>
    <a href="settings.php">⚙️ Settings</a>
    <a href="delivery.php" class="active">🛵 Delivery Boys</a>
  </nav>
</div>

<div class="container">

<?php if ($msg): ?>
  <div class="msg <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- Add New -->
<div class="card">
  <div class="card-title">➕ Naya Delivery Boy Add Karo</div>
  <form method="POST">
    <input type="hidden" name="action" value="add">
    <div class="form-row">
      <div>
        <label>Naam *</label>
        <input type="text" name="name" placeholder="e.g. Gurpreet Singh" required>
      </div>
      <div>
        <label>WhatsApp Number *</label>
        <input type="text" name="whatsapp_number" placeholder="e.g. 919876543210">
        <div class="hint">Country code sahit, bina + ke. e.g. 919876543210</div>
      </div>
    </div>
    <button type="submit" class="btn btn-green">✅ Add Karo</button>
  </form>
</div>

<!-- List -->
<div class="card">
  <div class="card-title">👥 Saare Delivery Boys (<?= count($boys) ?>)</div>
  <?php if (empty($boys)): ?>
    <div class="empty">
      <div style="font-size:36px;margin-bottom:8px">🛵</div>
      <div>Koi delivery boy nahi mila.</div>
      <div style="font-size:12px;margin-top:4px">Upar form bharo add karne laye.</div>
    </div>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table>
    <thead>
      <tr>
        <th>Naam</th>
        <th>WhatsApp</th>
        <th>Status</th>
        <th>Kiye Orders</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($boys as $b):
      try {
          $orderCount = $db->prepare("SELECT COUNT(*) FROM orders WHERE delivery_boy_id=?");
          $orderCount->execute([$b['id']]);
          $cnt = $orderCount->fetchColumn();
      } catch(Exception $e) { $cnt = 0; }
    ?>
    <tr>
      <td><strong><?= htmlspecialchars($b['name']) ?></strong></td>
      <td style="font-size:12px;color:#6b7280">+<?= htmlspecialchars($b['whatsapp_number']) ?></td>
      <td>
        <?php if ($b['is_active']): ?>
          <span class="badge-active">✅ Active</span>
        <?php else: ?>
          <span class="badge-inactive">⏸ Inactive</span>
        <?php endif; ?>
      </td>
      <td style="font-size:12px;color:#6b7280"><?= $cnt ?> orders</td>
      <td>
        <div class="actions">
          <button class="btn btn-outline" style="font-size:11px;padding:5px 10px"
                  onclick="toggleEdit(<?= $b['id'] ?>)">✏️ Edit</button>

          <?php if ($b['is_active']): ?>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= $b['id'] ?>">
              <input type="hidden" name="is_active" value="0">
              <button type="submit" class="btn btn-outline" style="font-size:11px;padding:5px 10px">⏸ Inactive</button>
            </form>
          <?php else: ?>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= $b['id'] ?>">
              <input type="hidden" name="is_active" value="1">
              <button type="submit" class="btn btn-outline" style="font-size:11px;padding:5px 10px;color:var(--green)">✅ Active</button>
            </form>
          <?php endif; ?>

          <form method="POST" style="display:inline"
                onsubmit="return confirm('<?= htmlspecialchars($b['name']) ?> nu delete karo?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $b['id'] ?>">
            <button type="submit" class="btn btn-red" style="font-size:11px;padding:5px 10px">🗑</button>
          </form>
        </div>

        <!-- Inline edit form -->
        <div class="edit-form" id="edit-<?= $b['id'] ?>">
          <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?= $b['id'] ?>">
            <div class="form-row">
              <div>
                <label>Naam</label>
                <input type="text" name="name" value="<?= htmlspecialchars($b['name']) ?>" required>
              </div>
              <div>
                <label>WhatsApp Number</label>
                <input type="text" name="whatsapp_number" value="<?= htmlspecialchars($b['whatsapp_number']) ?>" required>
              </div>
            </div>
            <div style="display:flex;gap:8px">
              <button type="submit" class="btn btn-green" style="font-size:12px;padding:7px 14px">💾 Save</button>
              <button type="button" class="btn btn-outline" style="font-size:12px;padding:7px 14px"
                      onclick="toggleEdit(<?= $b['id'] ?>)">Cancel</button>
            </div>
          </form>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<!-- Instructions -->
<div class="card" style="background:#fffbeb;border-color:#fde68a">
  <div class="card-title" style="color:#92400e">ℹ️ Kive Kaam Karda Hai</div>
  <ol style="font-size:13px;color:#78350f;line-height:2;padding-left:18px">
    <li>Delivery boy add karo upar form mein</li>
    <li>Orders page te jaao → order row mein <strong>Delivery Boy</strong> column</li>
    <li>Dropdown mein delivery boy choose karo</li>
    <li>Immediately delivery boy nu WhatsApp aavega order details + 2 buttons</li>
    <li><strong>✅ Delivered</strong> — Order delivered mark + customer/admin nu notify</li>
    <li><strong>❌ Issue Hai</strong> — Admin nu alert jaata hai</li>
  </ol>
  <div style="margin-top:12px;font-size:12px;color:#92400e;background:#fef3c7;padding:10px;border-radius:8px">
    <strong>⚠️ Note:</strong> WhatsApp number bilkul sahi hona chahida — country code sahit, bina + ke.<br>
    Example: India laye <code>91</code> + <code>9876543210</code> = <code>919876543210</code>
  </div>
</div>

</div>

<script>
function toggleEdit(id) {
    const el = document.getElementById('edit-' + id);
    el.style.display = el.style.display === 'block' ? 'none' : 'block';
}
</script>
</body>
</html>
