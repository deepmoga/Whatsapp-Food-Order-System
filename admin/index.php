<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';

session_start();
$adminPass = 'admin123';
if ($_POST['pass'] ?? '' === $adminPass) $_SESSION['admin'] = true;
if (!($_SESSION['admin'] ?? false)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') echo "<p style='color:red;font-family:sans-serif;padding:20px'>Wrong password</p>";
    echo '<!DOCTYPE html><html><head><title>Admin Login</title><meta name="viewport" content="width=device-width,initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:"Plus Jakarta Sans",sans-serif;background:#0f1117;color:var(--text);display:flex;justify-content:center;align-items:center;height:100vh}
    .box{background:#181c25;padding:36px;border-radius:16px;border:1px solid var(--border);text-align:center;width:320px}
    h2{font-size:20px;margin-bottom:6px}p{color:#7a8099;font-size:13px;margin-bottom:24px}
    input{width:100%;background:#1f2433;border:1px solid var(--border);border-radius:8px;padding:11px 14px;color:var(--text);font-size:14px;font-family:inherit;outline:none;margin-bottom:12px}
    button{width:100%;background:var(--green);color:#fff;border:none;border-radius:8px;padding:11px;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer}</style></head>
    <body><div class="box"><div style="font-size:36px;margin-bottom:12px">🍽</div><h2>Admin Login</h2><p>Restaurant Management Panel</p>
    <form method="POST"><input type="password" name="pass" placeholder="Password" autofocus><button>Login →</button></form></div></body></html>';
    exit;
}

$db = getDB(); $msg = '';

// ============================================
//  AJAX HANDLERS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');

    // Status update + send WhatsApp
    if ($_POST['ajax'] === 'update_status') {
        $id        = (int)$_POST['id'];
        $newStatus = $_POST['status'];
        $validStatuses = ['waiting','confirmed','preparing','ready','delivered','cancelled'];
        if (!in_array($newStatus, $validStatuses)) { echo json_encode(['ok'=>false,'msg'=>'Invalid status']); exit; }

        $db->prepare("UPDATE orders SET order_status=?, updated_at=NOW() WHERE id=?")->execute([$newStatus, $id]);

        // Fetch order for message
        $stmt = $db->prepare("SELECT * FROM orders WHERE id=?"); $stmt->execute([$id]); $order = $stmt->fetch();

        // Get message template
        $tpl = $db->prepare("SELECT * FROM status_messages WHERE status=? AND is_active=1");
        $tpl->execute([$newStatus]); $template = $tpl->fetch();

        $waResult = null;
        if ($template && $order) {
            $items     = json_decode($order['items'], true);
            $itemLines = implode("\n", array_map(fn($i) => "• {$i['name']} x{$i['qty']}", $items));
            $restName  = getSetting('restaurant_name', 'Restaurant');
            $estTime   = getSetting('estimated_time', '30-45');
            $reviewLink= getSetting('google_review_link', '');
            $restPhone = getSetting('restaurant_phone', '');
            $isPickup  = ($order['delivery_address'] ?? '') === 'PICKUP';
            $deliveryOrPickup = $isPickup ? "📍 Restaurant ton *pickup* karo ji." : "📍 *{$order['delivery_address']}* te deliver ho raha hai.";

            $msgText = $template['message'];
            $msgText = str_replace('{order_number}',   $order['order_number'],        $msgText);
            $msgText = str_replace('{name}',           $order['customer_name'],        $msgText);
            $msgText = str_replace('{items}',          $itemLines,                     $msgText);
            $msgText = str_replace('{total}',          $order['total'],                $msgText);
            $msgText = str_replace('{estimated_time}', $estTime,                       $msgText);
            $msgText = str_replace('{restaurant_name}',$restName,                      $msgText);
            $msgText = str_replace('{restaurant_phone}',$restPhone,                    $msgText);
            $msgText = str_replace('{review_link}',    $reviewLink ?: '_(link available soon)_', $msgText);
            $msgText = str_replace('{delivery_or_pickup}', $deliveryOrPickup,          $msgText);
            $msgText = str_replace('\n', "\n", $msgText);

            $waResult = sendWhatsApp($order['phone'], $msgText);
        }

        echo json_encode(['ok' => true, 'whatsapp_sent' => $waResult !== null, 'order_number' => $order['order_number'] ?? '']);
        exit;
    }

    // Edit order items + quantities
    if ($_POST['ajax'] === 'edit_order') {
        $id    = (int)$_POST['id'];
        $items = json_decode($_POST['items'], true);
        if (!$items || !$id) { echo json_encode(['ok'=>false,'msg'=>'Invalid data']); exit; }

        // Recalculate totals
        $subtotal = array_sum(array_map(fn($i) => $i['price'] * $i['qty'], $items));
        $stmt = $db->prepare("SELECT * FROM orders WHERE id=?"); $stmt->execute([$id]); $order = $stmt->fetch();
        $discount  = (float)($order['discount_amount'] ?? 0);
        $delivery  = (float)($order['delivery_charge']  ?? 0);
        $total     = $subtotal - $discount + $delivery;

        $db->prepare("UPDATE orders SET items=?, subtotal=?, total=?, updated_at=NOW() WHERE id=?")
           ->execute([json_encode($items), $subtotal, $total, $id]);

        echo json_encode(['ok' => true, 'new_total' => $total, 'new_subtotal' => $subtotal]);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;
}


// AJAX — latest order id
if (isset($_GET['ajax']) && $_GET['ajax'] === 'latest_order_id') {
    header('Content-Type: application/json');
    $row = $db->query("SELECT COALESCE(MAX(id),0) as id FROM orders")->fetch();
    echo json_encode(['id' => (int)$row['id']]);
    exit;
}

// AJAX — new orders since id
if (isset($_GET['ajax']) && $_GET['ajax'] === 'new_orders') {
    header('Content-Type: application/json');
    $afterId = (int)($_GET['after'] ?? 0);
    $rows = $db->prepare("SELECT id, order_number, customer_name, total, created_at FROM orders WHERE id > ? ORDER BY id ASC LIMIT 10");
    $rows->execute([$afterId]);
    $newOrders = $rows->fetchAll();
    $latestId  = $afterId;
    if (!empty($newOrders)) $latestId = (int)end($newOrders)['id'];
    echo json_encode(['orders' => $newOrders, 'latest_id' => $latestId]);
    exit;
}

// Status update (non-ajax fallback)
if (isset($_GET['status']) && isset($_GET['id'])) {
    $db->prepare("UPDATE orders SET order_status=? WHERE id=?")->execute([$_GET['status'], (int)$_GET['id']]);
    header("Location: ?"); exit;
}

// Fetch orders
$filter = $_GET['filter'] ?? 'today';
$whereClause = match($filter) {
    'today'   => "WHERE DATE(created_at) = CURDATE()",
    'pending' => "WHERE order_status IN ('waiting','confirmed','preparing')",
    'paid'    => "WHERE payment_status = 'paid'",
    'cod'     => "WHERE payment_method = 'cod'",
    default   => ""
};
$orders = $db->query("SELECT * FROM orders {$whereClause} ORDER BY created_at DESC LIMIT 200")->fetchAll();

// Stats
$todayOrders  = $db->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE()")->fetchColumn();
$pendingCount = $db->query("SELECT COUNT(*) FROM orders WHERE order_status IN ('waiting','confirmed','preparing')")->fetchColumn();
$todayRevenue = $db->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE(created_at)=CURDATE() AND payment_status IN ('paid','cod_pending')")->fetchColumn();
$totalRevenue = $db->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status IN ('paid','cod_pending')")->fetchColumn();

// Status message templates
$templates = $db->query("SELECT * FROM status_messages ORDER BY id")->fetchAll();
?>
<!DOCTYPE html>
<html lang="pa">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars(getSetting('restaurant_name','Restaurant')) ?> — Orders</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--green:#1db954;--green2:#17a349;--red:#e53935;--amber:#f59e0b;--blue:#3b82f6;--bg:#0f1117;--bg2:#181c25;--bg3:#1f2433;--border:rgba(255,255,255,.08);--text:#eef0f4;--muted:#7a8099;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg2);color:var(--text);min-height:100vh;}

/* HEADER */
.header{background:#fff;border-bottom:1px solid #e5e7eb;box-shadow:0 1px 3px rgba(0,0,0,.06);padding:0 16px;display:flex;align-items:center;gap:10px;height:58px;position:sticky;top:0;z-index:200;}
.header-title{font-size:15px;font-weight:700;color:#111;}
.header-sub{font-size:11px;color:#6b7280;}
.header-nav{margin-left:auto;display:flex;gap:4px;overflow-x:auto;-webkit-overflow-scrolling:touch;}
.header-nav a{font-size:12px;font-weight:600;padding:6px 10px;border-radius:8px;text-decoration:none;color:#6b7280;transition:all .2s;white-space:nowrap;}
.header-nav a:hover{background:#f3f4f6;color:#111;}
.header-nav a.active{background:var(--green);color:#fff;}

/* STATS */
.stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;padding:14px 16px;}
.stat{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px;}
.stat .num{font-size:24px;font-weight:800;color:#111;}
.stat .lbl{font-size:11px;color:#6b7280;margin-top:3px;}
.stat.highlight{border-color:rgba(29,185,84,.4);background:#f0fdf4;}
.stat.highlight .num{color:#16a34a;}

/* FILTERS */
.filters{padding:0 16px 12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
.filter-btn{padding:6px 14px;border-radius:20px;border:1px solid #e5e7eb;background:#fff;color:#6b7280;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;transition:all .2s;}
.filter-btn:hover,.filter-btn.active{background:var(--green);border-color:var(--green);color:#fff;}
#searchBox{background:#fff;border:1px solid #e5e7eb;border-radius:20px;padding:6px 14px;color:#111;font-size:12px;font-family:inherit;outline:none;margin-left:auto;min-width:0;flex:1;max-width:220px;}
#searchBox:focus{border-color:var(--green);}

/* TABLE */
.table-wrap{margin:0 16px 24px;background:#fff;border:1px solid #e5e7eb;border-radius:14px;overflow-x:auto;}
table{width:100%;border-collapse:collapse;min-width:600px;}
th{background:#f8fafc;padding:10px 14px;text-align:left;font-size:10px;font-weight:700;color:#6b7280;letter-spacing:1px;text-transform:uppercase;border-bottom:1px solid #e5e7eb;}
td{padding:12px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;vertical-align:top;color:#111;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:#fafafa;}
.order-no{font-weight:800;font-size:13px;color:#111;}
.customer-name{font-weight:600;color:#111;}
.customer-sub{font-size:11px;color:#6b7280;margin-top:2px;}
.price{font-weight:700;color:var(--green);font-size:14px;}
.items-list{font-size:12px;line-height:1.7;color:#6b7280;}
.items-list strong{color:#111;}

/* BADGES */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:10px;font-weight:700;}
.s-waiting  {background:#fef3c7;color:#92400e;border:1px solid #fde68a;}
.s-confirmed{background:#dbeafe;color:#1d4ed8;border:1px solid #bfdbfe;}
.s-preparing{background:#f3e8ff;color:#7c3aed;border:1px solid #e9d5ff;}
.s-ready    {background:#dcfce7;color:#16a34a;border:1px solid #bbf7d0;}
.s-delivered{background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;}
.s-cancelled{background:#fee2e2;color:#dc2626;border:1px solid #fecaca;}
.p-paid     {background:#dcfce7;color:#16a34a;border:1px solid #bbf7d0;}
.p-cod      {background:#fef3c7;color:#92400e;border:1px solid #fde68a;}
.p-pending  {background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;}

/* STATUS SELECT */
.status-select{background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;padding:5px 8px;color:#111;font-size:11px;font-family:inherit;cursor:pointer;outline:none;margin-top:6px;width:100%;}
.status-select:focus{border-color:var(--green);}

/* ACTIONS */
.action-btn{display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border-radius:6px;border:1px solid #e5e7eb;background:#fff;color:#6b7280;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .2s;margin-top:6px;}
.action-btn:hover{border-color:var(--green);color:var(--green);}

/* TOAST */
.toast{position:fixed;bottom:24px;right:24px;background:#111;border-radius:10px;padding:12px 18px;font-size:13px;font-weight:600;color:#fff;z-index:1000;transform:translateY(80px);opacity:0;transition:all .3s;max-width:320px;}
.toast.show{transform:translateY(0);opacity:1;}
.toast.success{background:#16a34a;color:#fff;}
.toast.error{background:#dc2626;color:#fff;}
.toast.new-order{background:var(--bg2);color:#1db954;border:1px solid rgba(29,185,84,.4);font-size:14px;padding:14px 20px;}

/* MODAL */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:300;display:flex;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(4px);opacity:0;pointer-events:none;transition:opacity .2s;}
.overlay.open{opacity:1;pointer-events:all;}
.modal{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:24px;width:100%;max-width:540px;max-height:90vh;overflow-y:auto;transform:scale(.95);transition:transform .2s;color:#111;}
.overlay.open .modal{transform:scale(1);}
.modal-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;}
.modal-title{font-size:16px;font-weight:700;color:#111;}
.modal-sub{font-size:12px;color:#6b7280;margin-top:2px;}
.close-btn{background:none;border:none;color:#6b7280;cursor:pointer;font-size:20px;line-height:1;padding:2px;}
.close-btn:hover{color:#111;}

/* ORDER EDITOR */
.item-editor{background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:14px;margin-bottom:10px;}
.item-editor-row{display:flex;align-items:center;gap:10px;}
.item-editor-name{flex:1;font-size:13px;font-weight:600;color:#111;}
.qty-input{width:60px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:6px 8px;color:#111;font-size:13px;text-align:center;font-family:inherit;outline:none;}
.qty-input:focus{border-color:var(--green);}
.item-price{font-size:13px;color:var(--green);font-weight:700;min-width:60px;text-align:right;}
.remove-item{background:none;border:none;color:#ef5350;cursor:pointer;font-size:16px;padding:2px 6px;}
.order-total-row{display:flex;justify-content:space-between;align-items:center;padding:12px 0 0;border-top:1px solid #e5e7eb;margin-top:8px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:8px;border:none;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .2s;}
.btn-green{background:var(--green);color:#fff;} .btn-green:hover{background:var(--green2);}
.btn-outline{background:#fff;border:1px solid #e5e7eb;color:#6b7280;}
.modal-footer{display:flex;gap:10px;justify-content:flex-end;margin-top:20px;}
.wa-status{font-size:11px;margin-top:4px;}

/* RESPONSIVE */
@media(max-width:640px){
  .header{gap:8px;}
  .header-nav a{padding:5px 7px;font-size:11px;}
  .stats{grid-template-columns:repeat(2,1fr);padding:10px 12px;gap:8px;}
  .stat .num{font-size:20px;}
  .filters{padding:0 12px 10px;gap:6px;}
  #searchBox{max-width:100%;margin-left:0;width:100%;}
  .table-wrap{margin:0 12px 16px;}
  td{padding:10px 10px;}
  th{padding:8px 10px;}
}
</style>
</head>
<body>

<div class="header">
  <span style="font-size:22px">🍽</span>
  <div><div class="header-title"><?= htmlspecialchars(getSetting('restaurant_name','Restaurant')) ?></div><div class="header-sub">Live Orders</div></div>
  <nav class="header-nav">
    <a href="index.php" class="active">📋 Orders</a>
    <a href="menu.php">🍛 Menu</a>
    <a href="bills.php">🧾 Bills</a>
    <a href="store-hours.php">🕐 Hours</a>
    <a href="coupons.php">🏷 Coupons</a>
    <a href="settings.php">⚙️ Settings</a>
  </nav>
</div>


<!-- Notification status bar -->
<div id="notifBanner" style="display:none;background:#1db954;color:#fff;padding:8px 16px;font-size:13px;font-weight:600;align-items:center;gap:10px;">
  🔔 <span>Naye orders di notifications enable karo</span>
  <button onclick="askNotifPermission()"
          style="background:rgba(255,255,255,.25);border:none;color:#fff;padding:4px 14px;border-radius:6px;cursor:pointer;font-weight:700;font-family:inherit">
    Allow Karo
  </button>
  <button onclick="this.closest('#notifBanner').style.display='none'"
          style="background:none;border:none;color:rgba(255,255,255,.7);cursor:pointer;margin-left:auto;font-size:18px;line-height:1">✕</button>
</div>
<script>
// Auto-request notifications on page load (no click needed)
async function askNotifPermission() {
    if (!('Notification' in window)) return;
    if (Notification.permission === 'granted') { notifGranted = true; document.getElementById('notifBanner').style.display='none'; return; }
    const p = await Notification.requestPermission();
    notifGranted = (p === 'granted');
    document.getElementById('notifBanner').style.display = 'none';
}
// Show banner only if blocked or default
if ('Notification' in window && Notification.permission !== 'granted') {
    document.getElementById('notifBanner').style.display = 'flex';
    if (Notification.permission === 'default') askNotifPermission();
}
</script>

<!-- Stats -->
<div class="stats">
  <div class="stat"><div class="num"><?= $todayOrders ?></div><div class="lbl">Aaj de Orders</div></div>
  <div class="stat"><div class="num" style="color:var(--amber)"><?= $pendingCount ?></div><div class="lbl">Pending</div></div>
  <div class="stat highlight"><div class="num">₹<?= number_format($todayRevenue,0) ?></div><div class="lbl">Aaj di Revenue</div></div>
  <div class="stat"><div class="num">₹<?= number_format($totalRevenue,0) ?></div><div class="lbl">Total Revenue</div></div>
</div>

<!-- Filters -->
<div class="filters">
  <a href="?filter=today"   class="filter-btn <?= $filter==='today'  ?'active':'' ?>">📅 Aaj</a>
  <a href="?filter=pending" class="filter-btn <?= $filter==='pending'?'active':'' ?>">⏳ Pending</a>
  <a href="?filter=cod"     class="filter-btn <?= $filter==='cod'    ?'active':'' ?>">💵 COD</a>
  <a href="?filter=paid"    class="filter-btn <?= $filter==='paid'   ?'active':'' ?>">💳 Paid</a>
  <a href="?filter=all"     class="filter-btn <?= $filter==='all'    ?'active':'' ?>">📋 All</a>
  <input type="text" id="searchBox" placeholder="🔍 Order/name search...">
</div>

<!-- Orders Table -->
<div class="table-wrap">
<table id="ordersTable">
<thead>
  <tr>
    <th>Order</th>
    <th>Customer</th>
    <th>Items</th>
    <th>Total</th>
    <th>Payment</th>
    <th>Status + Action</th>
    <th>Time</th>
  </tr>
</thead>
<tbody>
<?php foreach ($orders as $o):
  $items = json_decode($o['items'], true);
  $pClass = match($o['payment_status']) { 'paid' => 'p-paid', 'cod_pending' => 'p-cod', default => 'p-pending' };
  $pLabel = match($o['payment_status']) { 'paid' => '✅ Paid', 'cod_pending' => '💵 COD', default => '⏳ Pending' };
?>
<tr data-id="<?= $o['id'] ?>" data-search="<?= strtolower($o['order_number'].' '.$o['customer_name'].' '.($o['customer_phone']??'')) ?>">
  <td>
    <div class="order-no">#<?= htmlspecialchars($o['order_number']) ?></div>
    <div style="font-size:10px;color:var(--muted);margin-top:2px">ID <?= $o['id'] ?></div>
  </td>
  <td>
    <div class="customer-name"><?= htmlspecialchars($o['customer_name'] ?: '—') ?></div>
    <?php if (!empty($o['customer_phone'])): ?>
      <div class="customer-sub">📞 <?= htmlspecialchars($o['customer_phone']) ?></div>
    <?php endif; ?>
    <div class="customer-sub">📱 +<?= htmlspecialchars($o['phone']) ?></div>
    <?php if (!empty($o['delivery_address'])): ?>
      <div class="customer-sub" style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= htmlspecialchars($o['delivery_address']) ?>">
        📍 <?= htmlspecialchars($o['delivery_address']) ?>
      </div>
      <?php if (!empty($o['customer_lat']) && !empty($o['customer_lng'])): ?>
        <button class="action-btn" style="margin-top:4px;color:#3b82f6;border-color:rgba(59,130,246,.3)"
          onclick="openMap(<?= $o['customer_lat'] ?>, <?= $o['customer_lng'] ?>, '<?= htmlspecialchars(addslashes($o['customer_name'])) ?>')">
          🗺️ Map Dekho
        </button>
      <?php endif; ?>
    <?php endif; ?>
  </td>
  <td>
    <div class="items-list">
      <?php foreach ($items as $item): ?>
        <div><strong><?= htmlspecialchars($item['name']) ?></strong> ×<?= $item['qty'] ?></div>
      <?php endforeach; ?>
    </div>
    <button class="action-btn" onclick="openEditModal(<?= $o['id'] ?>, <?= htmlspecialchars(json_encode($items), ENT_QUOTES) ?>, '<?= $o['order_number'] ?>', <?= $o['total'] ?>)">
      ✏️ Edit Items
    </button>
  </td>
  <td>
    <div class="price">₹<?= number_format($o['total'],0) ?></div>
    <?php if ($o['discount_amount'] > 0): ?><div style="font-size:11px;color:var(--muted)">-₹<?= $o['discount_amount'] ?> off</div><?php endif; ?>
    <?php if ($o['delivery_charge'] > 0): ?><div style="font-size:11px;color:var(--muted)">+₹<?= $o['delivery_charge'] ?> del</div><?php endif; ?>
  </td>
  <td><span class="badge <?= $pClass ?>"><?= $pLabel ?></span></td>
  <td>
    <span class="badge s-<?= $o['order_status'] ?>" id="badge-<?= $o['id'] ?>"><?= strtoupper($o['order_status']) ?></span>
    <div class="wa-status" id="wa-<?= $o['id'] ?>"></div>
    <select class="status-select" onchange="updateStatus(<?= $o['id'] ?>, this.value, this)">
      <?php foreach (['waiting','confirmed','preparing','ready','delivered','cancelled'] as $s): ?>
        <option value="<?= $s ?>" <?= $o['order_status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
  </td>
  <td style="white-space:nowrap;font-size:12px;color:var(--muted)">
    <?= date('d M', strtotime($o['created_at'])) ?><br>
    <?= date('h:i A', strtotime($o['created_at'])) ?>
  </td>
</tr>
<?php endforeach; ?>
<?php if (empty($orders)): ?>
  <tr><td colspan="7" style="text-align:center;padding:48px;color:var(--muted)"><div style="font-size:36px;margin-bottom:12px">📋</div><div>Koi order nahi</div></td></tr>
<?php endif; ?>
</tbody>
</table>
</div>

<!-- Edit Items Modal -->
<div class="overlay" id="editOverlay">
<div class="modal">
  <div class="modal-header">
    <div><div class="modal-title">✏️ Order Edit Karo</div><div class="modal-sub" id="editOrderLabel">Order #</div></div>
    <button class="close-btn" onclick="closeModal()">✕</button>
  </div>
  <div id="itemEditorList"></div>
  <div class="order-total-row">
    <div style="font-size:13px;color:var(--muted)">New Total</div>
    <div class="price" id="editTotalDisplay">₹0</div>
  </div>
  <div class="modal-footer">
    <button class="btn btn-outline" onclick="closeModal()">Cancel</button>
    <button class="btn btn-green" onclick="saveEditedOrder()">💾 Save Changes</button>
  </div>
</div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<!-- Map Modal -->
<div class="overlay" id="mapOverlay">
  <div class="modal" style="max-width:600px;padding:0;overflow:hidden">
    <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid #e5e7eb;">
      <div>
        <div style="font-size:15px;font-weight:700;color:#111">🗺️ Customer Location</div>
        <div id="mapName" style="font-size:12px;color:#6b7280;margin-top:2px"></div>
      </div>
      <div style="display:flex;gap:8px;align-items:center">
        <a id="mapGoogleLink" href="#" target="_blank"
           style="font-size:12px;font-weight:600;color:#3b82f6;text-decoration:none;padding:6px 12px;border:1px solid #bfdbfe;border-radius:8px">
          🔗 Google Maps mein khullo
        </a>
        <button class="close-btn" onclick="closeMap()">✕</button>
      </div>
    </div>
    <iframe id="mapFrame" src="" width="100%" height="400"
      style="border:none;display:block" loading="lazy" allowfullscreen
      referrerpolicy="no-referrer-when-downgrade"></iframe>
  </div>
</div>

<script>
let editOrderId = null;
let editItems   = [];

// ---- STATUS UPDATE ----
async function updateStatus(id, newStatus, selectEl) {
    const badge = document.getElementById('badge-' + id);
    const waEl  = document.getElementById('wa-' + id);
    waEl.textContent = '⏳ Sending...';
    waEl.style.color = '#7a8099';

    try {
        const fd = new FormData();
        fd.append('ajax', 'update_status');
        fd.append('id', id);
        fd.append('status', newStatus);

        const res  = await fetch('index.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.ok) {
            // Update badge
            badge.className = 'badge s-' + newStatus;
            badge.textContent = newStatus.toUpperCase();

            if (data.whatsapp_sent) {
                waEl.textContent = '✅ WhatsApp sent';
                waEl.style.color = 'var(--green)';
                showToast('✅ Status updated + WhatsApp sent!', 'success');
            } else {
                waEl.textContent = '⚠️ WA not sent';
                waEl.style.color = '#f59e0b';
                showToast('✅ Status updated (no WA template)', 'success');
            }
            setTimeout(() => { waEl.textContent = ''; }, 5000);
        } else {
            showToast('❌ Error: ' + data.msg, 'error');
        }
    } catch(e) {
        showToast('❌ Network error', 'error');
    }
}

// ---- EDIT MODAL ----
function openEditModal(orderId, items, orderNo, total) {
    editOrderId = orderId;
    editItems   = JSON.parse(JSON.stringify(items)); // deep copy
    document.getElementById('editOrderLabel').textContent = 'Order #' + orderNo;
    renderItemEditor();
    document.getElementById('editOverlay').classList.add('open');
}

function closeModal() {
    document.getElementById('editOverlay').classList.remove('open');
}

function renderItemEditor() {
    const list = document.getElementById('itemEditorList');
    list.innerHTML = '';
    editItems.forEach((item, idx) => {
        const div = document.createElement('div');
        div.className = 'item-editor';
        div.innerHTML = `
            <div class="item-editor-row">
                <div class="item-editor-name">${item.name}</div>
                <button class="remove-item" onclick="removeEditItem(${idx})" title="Remove">🗑</button>
            </div>
            <div class="item-editor-row" style="margin-top:8px">
                <div style="font-size:12px;color:var(--muted)">Qty:</div>
                <input type="number" class="qty-input" value="${item.qty}" min="1" max="20"
                    onchange="updateEditQty(${idx}, this.value)" oninput="updateEditQty(${idx}, this.value)">
                <div style="font-size:12px;color:var(--muted)">× ₹${item.price} =</div>
                <div class="item-price" id="item-subtotal-${idx}">₹${(item.price * item.qty).toFixed(0)}</div>
            </div>`;
        list.appendChild(div);
    });
    updateEditTotal();
}

function updateEditQty(idx, val) {
    const qty = Math.max(1, Math.min(20, parseInt(val) || 1));
    editItems[idx].qty = qty;
    const el = document.getElementById('item-subtotal-' + idx);
    if (el) el.textContent = '₹' + (editItems[idx].price * qty).toFixed(0);
    updateEditTotal();
}

function removeEditItem(idx) {
    if (editItems.length <= 1) { showToast('Minimum 1 item zaroori hai', 'error'); return; }
    editItems.splice(idx, 1);
    renderItemEditor();
}

function updateEditTotal() {
    const total = editItems.reduce((s, i) => s + i.price * i.qty, 0);
    document.getElementById('editTotalDisplay').textContent = '₹' + total.toFixed(0);
}

async function saveEditedOrder() {
    if (!editOrderId) return;
    const fd = new FormData();
    fd.append('ajax', 'edit_order');
    fd.append('id', editOrderId);
    fd.append('items', JSON.stringify(editItems));
    try {
        const res  = await fetch('index.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            showToast('✅ Order updated! New total: ₹' + data.new_total, 'success');
            closeModal();
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast('❌ ' + data.msg, 'error');
        }
    } catch(e) { showToast('❌ Network error', 'error'); }
}

// ---- SEARCH ----
document.getElementById('searchBox').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#ordersTable tbody tr').forEach(tr => {
        tr.style.display = tr.dataset.search?.includes(q) ? '' : 'none';
    });
});

// ---- TOAST ----
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast ' + type + ' show';
    setTimeout(() => t.classList.remove('show'), 3500);
}

// Close modal on overlay click
document.getElementById('editOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});


// ============================================
//  WINDOW NOTIFICATIONS — New Order Alert
// ============================================
const POLL_INTERVAL = 15000; // 15 seconds
let lastOrderId    = 0;
let notifGranted   = (typeof Notification !== 'undefined' && Notification.permission === 'granted');
let audioCtx       = null;
let audioUnlocked  = false;

// Unlock audio on first user interaction (browser requirement)
document.addEventListener('click', () => { audioUnlocked = true; }, { once: true });
document.addEventListener('keydown', () => { audioUnlocked = true; }, { once: true });

async function initNotifications() {
    if (!('Notification' in window)) return;
    // Already granted — just set flag and get baseline
    if (Notification.permission === 'granted') {
        notifGranted = true;
    }
    // Get current latest order id as baseline
    try {
        const res  = await fetch('index.php?ajax=latest_order_id');
        const data = await res.json();
        lastOrderId = data.id || 0;
    } catch(e) {}
}

// Beep sound — triple ding
function playBeep() {
    if (!audioUnlocked) return; // browser blocks audio before interaction
    try {
        if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        if (audioCtx.state === 'suspended') audioCtx.resume();
        [0, 350, 700].forEach((delay, i) => {
            const osc  = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.frequency.value = i === 0 ? 880 : (i === 1 ? 1046 : 1318); // Do-Mi-Sol chord
            osc.type = 'sine';
            const t = audioCtx.currentTime + delay / 1000;
            gain.gain.setValueAtTime(0, t);
            gain.gain.linearRampToValueAtTime(0.5, t + 0.05);
            gain.gain.exponentialRampToValueAtTime(0.001, t + 0.4);
            osc.start(t);
            osc.stop(t + 0.4);
        });
    } catch(e) {}
}

// Show new order notification
function showOrderNotification(order) {
    playBeep();

    // Browser notification
    if (notifGranted) {
        try {
            const n = new Notification('🔔 Naya Order Aaya!', {
                body: '#' + order.order_number + '\n' + order.customer_name + ' — ₹' + order.total,
                icon: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><text y=".9em" font-size="90">🍽</text></svg>',
                tag:  'order-' + order.id,
                requireInteraction: true,
                silent: false,
            });
            n.onclick = () => { window.focus(); n.close(); };
        } catch(e) {}
    }

    // In-page toast
    showToast('🔔 Naya Order! #' + order.order_number + ' — ₹' + order.total, 'new-order');

    // Blink title
    let blink = true;
    const orig = document.title;
    const iv = setInterval(() => {
        document.title = blink ? '🔔 NAYA ORDER!' : orig;
        blink = !blink;
    }, 700);
    setTimeout(() => { clearInterval(iv); document.title = orig; }, 12000);
}

// Poll for new orders
async function pollNewOrders() {
    try {
        const res  = await fetch('index.php?ajax=new_orders&after=' + lastOrderId);
        const data = await res.json();
        if (data.orders && data.orders.length > 0) {
            data.orders.forEach(order => {
                if (order.id > lastOrderId) showOrderNotification(order);
            });
            lastOrderId = data.latest_id;
            setTimeout(() => location.reload(), 3000);
        }
    } catch(e) {}
}

// ============================================
//  MAP MODAL
// ============================================
function openMap(lat, lng, name) {
    document.getElementById('mapName').textContent  = name || 'Customer Location';
    document.getElementById('mapFrame').src =
        'https://maps.google.com/maps?q=' + lat + ',' + lng +
        '&z=16&output=embed&hl=pa';
    document.getElementById('mapGoogleLink').href =
        'https://www.google.com/maps?q=' + lat + ',' + lng;
    document.getElementById('mapOverlay').classList.add('open');
}
function closeMap() {
    document.getElementById('mapOverlay').classList.remove('open');
    document.getElementById('mapFrame').src = '';
}
document.getElementById('mapOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeMap();
});

// Init
initNotifications();
setInterval(pollNewOrders, POLL_INTERVAL);
setTimeout(() => location.reload(), 60000);
</script>
</body>
</html>
