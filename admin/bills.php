<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
session_start();
if (!($_SESSION['admin'] ?? false)) { header('Location: index.php'); exit; }

$db = getDB();

// Ensure all required columns exist safely
$safeAlter = function($sql) use ($db) {
    try { $db->exec($sql); } catch(Exception $e) {}
};
$safeAlter("ALTER TABLE orders ADD COLUMN IF NOT EXISTS bill_token VARCHAR(64) DEFAULT NULL");
$safeAlter("ALTER TABLE orders ADD COLUMN IF NOT EXISTS bill_viewed_at TIMESTAMP NULL DEFAULT NULL");
$safeAlter("ALTER TABLE orders ADD COLUMN IF NOT EXISTS gst_amount DECIMAL(10,2) DEFAULT 0");
$safeAlter("ALTER TABLE orders ADD COLUMN IF NOT EXISTS customer_phone VARCHAR(20) DEFAULT NULL");
$safeAlter("ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(10,2) DEFAULT 0");
$safeAlter("ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_charge DECIMAL(10,2) DEFAULT 0");
$safeAlter("ALTER TABLE orders ADD COLUMN IF NOT EXISTS coupon_code VARCHAR(30) DEFAULT NULL");
$safeAlter("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_method VARCHAR(20) DEFAULT 'online'");
$safeAlter("ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_address TEXT DEFAULT NULL");
$safeAlter("ALTER TABLE orders ADD COLUMN IF NOT EXISTS order_status VARCHAR(30) DEFAULT 'waiting'");
$safeAlter("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_status VARCHAR(30) DEFAULT 'pending'");
$safeAlter("ALTER TABLE orders ADD COLUMN IF NOT EXISTS subtotal DECIMAL(10,2) DEFAULT 0");
$safeAlter("ALTER TABLE orders ADD COLUMN IF NOT EXISTS total DECIMAL(10,2) DEFAULT 0");
$safeAlter("ALTER TABLE orders ADD COLUMN IF NOT EXISTS distance_km DECIMAL(5,2) DEFAULT NULL");
$safeAlter("ALTER TABLE orders ADD COLUMN IF NOT EXISTS customer_name VARCHAR(100) DEFAULT ''");

// Generate bill token (AJAX)
if (isset($_GET['gen_token'])) {
    header('Content-Type: application/json');
    $id    = (int)$_GET['gen_token'];
    $token = generateBillToken($id);
    echo json_encode(['token' => $token, 'url' => getBillUrl($token)]);
    exit;
}

// Filters
$dateFrom = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
$dateTo   = $_GET['to']   ?? date('Y-m-d');
$status   = $_GET['status'] ?? '';
$search   = trim($_GET['q'] ?? '');

$where  = "WHERE DATE(created_at) BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];
if ($status) { $where .= " AND order_status = ?"; $params[] = $status; }
if ($search) {
    $where   .= " AND (order_number LIKE ? OR customer_name LIKE ? OR phone LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}

$stmt = $db->prepare("SELECT * FROM orders {$where} ORDER BY created_at DESC LIMIT 500");
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Stats — safe COALESCE for optional columns
$statsStmt = $db->prepare("SELECT
    COUNT(*) as total_orders,
    COALESCE(SUM(total),0) as total_revenue,
    COALESCE(SUM(CASE WHEN payment_status='paid' THEN total ELSE 0 END),0) as paid_revenue,
    COALESCE(SUM(CASE WHEN IFNULL(payment_method,'online')='cod' THEN total ELSE 0 END),0) as cod_revenue,
    COALESCE(SUM(IFNULL(discount_amount,0)),0) as total_discounts,
    COALESCE(SUM(IFNULL(gst_amount,0)),0) as total_gst
    FROM orders WHERE DATE(created_at) BETWEEN ? AND ?");
$statsStmt->execute([$dateFrom, $dateTo]);
$stat = $statsStmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bills & Reports — <?= htmlspecialchars(getSetting('restaurant_name','Restaurant')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--green:#1db954;--green2:#17a349;--red:#e53935;--amber:#f59e0b;--blue:#3b82f6;--bg:#0f1117;--bg2:#181c25;--bg3:#1f2433;--border:rgba(255,255,255,.08);--text:#eef0f4;--muted:#7a8099;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg2);color:var(--text);min-height:100vh;}
.header{background:#fff;border-bottom:1px solid var(--border);padding:0 24px;display:flex;align-items:center;gap:14px;height:58px;position:sticky;top:0;z-index:100;box-shadow:0 1px 3px rgba(0,0,0,.06);}
.logo{font-size:22px;}
.header-title{font-size:15px;font-weight:700;color:var(--text);}
.header-sub{font-size:11px;color:var(--muted);}
.header-nav{margin-left:auto;display:flex;gap:6px;flex-wrap:wrap;}
.header-nav a{font-size:12px;font-weight:600;padding:6px 12px;border-radius:8px;text-decoration:none;color:var(--muted);transition:all .15s;}
.header-nav a:hover{background:var(--bg3);color:var(--text);}
.header-nav a.active{background:var(--green);color:#fff;}

.stats-bar{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;padding:20px 24px;}
.stat{background:#fff;border:1px solid var(--border);border-radius:12px;padding:16px 18px;box-shadow:0 1px 3px rgba(0,0,0,.05);}
.stat .num{font-size:22px;font-weight:800;color:var(--text);}
.stat .lbl{font-size:11px;color:var(--muted);margin-top:3px;text-transform:uppercase;letter-spacing:.4px;}
.stat.green .num{color:#16a34a;}
.stat.amber .num{color:var(--amber);}

.filters{background:#fff;border-bottom:1px solid var(--border);padding:12px 24px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
.filters input,.filters select{background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:7px 12px;color:var(--text);font-size:13px;font-family:inherit;outline:none;}
.filters input:focus,.filters select:focus{border-color:var(--green);}
.btn{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:8px;border:none;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .15s;}
.btn-green{background:var(--green);color:#fff;}
.btn-green:hover{background:var(--green2);}
.btn-outline{background:#fff;border:1px solid var(--border);color:var(--muted);}
.btn-outline:hover{border-color:var(--green);color:var(--green);}
.ds{padding:5px 10px;border-radius:6px;border:1px solid var(--border);background:#fff;color:var(--muted);font-size:11px;cursor:pointer;font-family:inherit;}
.ds:hover{border-color:var(--green);color:var(--green);}

.table-wrap{margin:16px 24px;background:#fff;border:1px solid var(--border);border-radius:14px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);}
table{width:100%;border-collapse:collapse;}
th{background:var(--bg2);padding:10px 14px;text-align:left;font-size:10px;font-weight:700;color:var(--muted);letter-spacing:1px;text-transform:uppercase;border-bottom:1px solid var(--border);}
td{padding:11px 14px;border-bottom:1px solid var(--bg3);font-size:13px;vertical-align:top;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:#fafafa;}
.order-no{font-weight:800;font-size:12px;color:var(--text);}
.customer{font-weight:600;}
.sub{font-size:11px;color:var(--muted);margin-top:2px;}
.amount{font-weight:800;font-size:15px;color:#16a34a;}
.badge{display:inline-flex;align-items:center;padding:3px 8px;border-radius:12px;font-size:10px;font-weight:700;}
.b-paid{background:#dcfce7;color:#16a34a;}
.b-cod{background:#fef3c7;color:#92400e;}
.b-pend{background:#f3f4f6;color:#6b7280;}
.bill-link{display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border-radius:6px;border:1px solid var(--border);background:#fff;color:var(--muted);font-size:11px;font-weight:600;cursor:pointer;text-decoration:none;transition:all .15s;}
.bill-link:hover{border-color:var(--green);color:var(--green);}
.copy-btn{display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border-radius:6px;border:1px solid var(--border);background:#fff;color:var(--muted);font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .15s;margin-top:4px;}
.copy-btn:hover{border-color:var(--amber);color:var(--amber);}
.toast{position:fixed;bottom:20px;right:20px;background:#111;border-radius:10px;padding:11px 16px;font-size:13px;font-weight:600;color:#fff;z-index:1000;transform:translateY(60px);opacity:0;transition:all .3s;}
.toast.show{transform:translateY(0);opacity:1;}
</style>
</head>
<body>
<div class="header">
  <span class="logo">🍽</span>
  <div><div class="header-title"><?= htmlspecialchars(getSetting('restaurant_name','Restaurant')) ?></div>
  <div class="header-sub">Bills & Reports</div></div>
  <nav class="header-nav">
    <a href="index.php">📋 Orders</a>
    <a href="menu.php">🍛 Menu</a>
    <a href="bills.php" class="active">🧾 Bills</a>
    <a href="coupons.php">🏷 Coupons</a>
    <a href="store-hours.php">🕐 Hours</a>
    <a href="settings.php">⚙️ Settings</a>
  </nav>
</div>

<div class="stats-bar">
  <div class="stat green"><div class="num">₹<?= number_format($stat['total_revenue'],0) ?></div><div class="lbl">Total Revenue</div></div>
  <div class="stat green"><div class="num">₹<?= number_format($stat['paid_revenue'],0) ?></div><div class="lbl">Online Paid</div></div>
  <div class="stat amber"><div class="num">₹<?= number_format($stat['cod_revenue'],0) ?></div><div class="lbl">COD Revenue</div></div>
  <div class="stat"><div class="num"><?= $stat['total_orders'] ?></div><div class="lbl">Orders</div></div>
  <div class="stat"><div class="num">₹<?= number_format($stat['total_gst'],0) ?></div><div class="lbl">GST Collected</div></div>
  <div class="stat"><div class="num">₹<?= number_format($stat['total_discounts'],0) ?></div><div class="lbl">Discounts</div></div>
</div>

<form method="GET" class="filters">
  <button type="button" class="ds" onclick="setDates(0)">Aaj</button>
  <button type="button" class="ds" onclick="setDates(7)">7 Din</button>
  <button type="button" class="ds" onclick="setDates(30)">30 Din</button>
  <button type="button" class="ds" onclick="setDates(90)">3 Mahine</button>
  <input type="date" name="from" id="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
  <span style="color:var(--muted);font-size:12px">to</span>
  <input type="date" name="to" id="dateTo" value="<?= htmlspecialchars($dateTo) ?>">
  <select name="status">
    <option value="">All Status</option>
    <?php foreach(['waiting','confirmed','preparing','ready','delivered','cancelled'] as $st): ?>
    <option value="<?= $st ?>" <?= $status===$st?'selected':'' ?>><?= ucfirst($st) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search order/name/phone...">
  <button type="submit" class="btn btn-green">🔍 Filter</button>
  <a href="bills.php" class="btn btn-outline">Reset</a>
  <span style="font-size:12px;color:var(--muted);margin-left:auto"><?= count($orders) ?> orders</span>
</form>

<div class="table-wrap">
<table>
<thead>
<tr><th>Order</th><th>Customer</th><th>Items</th><th>Amount</th><th>Payment</th><th>Bill</th></tr>
</thead>
<tbody>
<?php foreach ($orders as $o):
    $items = json_decode($o['items'] ?? '[]', true) ?: [];
    $pBadge = match($o['payment_status'] ?? '') {
        'paid'        => ['class'=>'b-paid','label'=>'Paid'],
        'cod_pending' => ['class'=>'b-cod', 'label'=>'COD'],
        default       => ['class'=>'b-pend','label'=>'Pending']
    };
    $billUrl = !empty($o['bill_token']) ? getBillUrl($o['bill_token']) : '';
?>
<tr>
  <td>
    <div class="order-no">#<?= htmlspecialchars($o['order_number']) ?></div>
    <div class="sub"><?= date('d M Y', strtotime($o['created_at'])) ?></div>
    <div class="sub"><?= date('h:i A', strtotime($o['created_at'])) ?></div>
  </td>
  <td>
    <div class="customer"><?= htmlspecialchars($o['customer_name'] ?: '—') ?></div>
    <?php if (!empty($o['customer_phone'])): ?><div class="sub">📞 <?= htmlspecialchars($o['customer_phone']) ?></div><?php endif; ?>
    <div class="sub">📱 +<?= htmlspecialchars($o['phone']) ?></div>
    <?php if (!empty($o['delivery_address'])): ?>
    <div class="sub" style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">📍 <?= htmlspecialchars($o['delivery_address']) ?></div>
    <?php endif; ?>
  </td>
  <td>
    <?php foreach(array_slice($items,0,3) as $item): ?>
    <div style="font-size:11px;color:var(--muted)"><?= htmlspecialchars($item['name']) ?> ×<?= $item['qty'] ?></div>
    <?php endforeach; ?>
    <?php if(count($items)>3): ?><div style="font-size:10px;color:var(--muted)">+<?= count($items)-3 ?> more</div><?php endif; ?>
  </td>
  <td>
    <div class="amount">₹<?= number_format($o['total'] ?? 0, 0) ?></div>
    <?php if(!empty($o['delivery_charge'])&&$o['delivery_charge']>0): ?><div class="sub">+₹<?= $o['delivery_charge'] ?> del</div><?php endif; ?>
    <?php if(!empty($o['gst_amount'])&&$o['gst_amount']>0): ?><div class="sub">+₹<?= $o['gst_amount'] ?> GST</div><?php endif; ?>
    <?php if(!empty($o['discount_amount'])&&$o['discount_amount']>0): ?><div class="sub" style="color:#16a34a">-₹<?= $o['discount_amount'] ?></div><?php endif; ?>
  </td>
  <td>
    <span class="badge <?= $pBadge['class'] ?>"><?= $pBadge['label'] ?></span>
    <div class="sub" style="margin-top:4px"><?= ucfirst($o['payment_method'] ?? 'online') ?></div>
    <div class="sub"><?= ucfirst($o['order_status'] ?? '') ?></div>
  </td>
  <td>
    <?php if ($billUrl): ?>
      <a href="<?= htmlspecialchars($billUrl) ?>" target="_blank" class="bill-link">🧾 View Bill</a><br>
      <button class="copy-btn" onclick="copyLink('<?= htmlspecialchars($billUrl) ?>', this)">📋 Copy Link</button>
      <?php if(!empty($o['bill_viewed_at'])): ?>
      <div class="sub" style="color:#16a34a;margin-top:3px">✅ <?= date('d M h:i A', strtotime($o['bill_viewed_at'])) ?></div>
      <?php else: ?><div class="sub" style="margin-top:3px">Not viewed</div><?php endif; ?>
    <?php else: ?>
      <button class="bill-link" onclick="genToken(<?= $o['id'] ?>, this)">⚡ Generate Bill</button>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
<?php if(empty($orders)): ?>
<tr><td colspan="6" style="text-align:center;padding:48px;color:var(--muted)"><div style="font-size:36px;margin-bottom:10px">🧾</div>Is date range mein koi orders nahi</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
<div class="toast" id="toast"></div>
<script>
function setDates(days){
    const to=new Date(), from=new Date();
    from.setDate(from.getDate()-days);
    document.getElementById('dateTo').value=to.toISOString().split('T')[0];
    document.getElementById('dateFrom').value=from.toISOString().split('T')[0];
    document.querySelector('.filters').submit();
}
function copyLink(url,btn){
    navigator.clipboard.writeText(url).then(()=>{
        btn.textContent='✅ Copied!';
        showToast('Bill link copied!');
        setTimeout(()=>btn.textContent='📋 Copy Link',2000);
    });
}
async function genToken(id,btn){
    btn.textContent='⏳...'; btn.disabled=true;
    const res=await fetch('bills.php?gen_token='+id);
    const data=await res.json();
    if(data.url){
        btn.outerHTML=`<a href="${data.url}" target="_blank" class="bill-link">🧾 View Bill</a><br><button class="copy-btn" onclick="copyLink('${data.url}',this)">📋 Copy Link</button>`;
        showToast('Bill ready!');
    }
}
function showToast(m){
    const t=document.getElementById('toast');
    t.textContent=m; t.classList.add('show');
    setTimeout(()=>t.classList.remove('show'),2500);
}
</script>
</body>
</html>
