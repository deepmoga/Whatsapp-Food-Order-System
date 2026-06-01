<?php
// ============================================
//  PUBLIC BILL VIEW — bill/view.php
//  Customer yeh link open karta hai
//  No login required — token based access
// ============================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';

$token = trim($_GET['t'] ?? '');
if (!$token || strlen($token) < 8) {
    http_response_code(404);
    die('<div style="font-family:sans-serif;text-align:center;padding:60px;color:#666">
         <div style="font-size:48px">❌</div>
         <h2>Bill nahi mila</h2>
         <p>Link galat hai ya expired ho gaya.</p></div>');
}

$db   = getDB();
$stmt = $db->prepare("SELECT * FROM orders WHERE bill_token = ?");
$stmt->execute([$token]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    die('<div style="font-family:sans-serif;text-align:center;padding:60px;color:#666">
         <div style="font-size:48px">❌</div><h2>Bill nahi mila</h2></div>');
}

// Mark as viewed
if (!$order['bill_viewed_at']) {
    $db->prepare("UPDATE orders SET bill_viewed_at=NOW() WHERE id=?")->execute([$order['id']]);
}

$items    = json_decode($order['items'], true) ?: [];
$restName = getSetting('restaurant_name', 'Restaurant');
$restAddr = getSetting('restaurant_address', '');
$restPh   = getSetting('restaurant_phone', '');
$gstin    = getSetting('restaurant_gstin', '');
$tagline  = getSetting('restaurant_tagline', '');
$logoUrl  = getSetting('restaurant_logo_url', '');
$footer   = getSetting('bill_footer_text', 'Shukriya! Dobara aana ji');
$gstPct   = getSetting('gst_percent', '5');
$gstEnabled = getSetting('gst_enabled', '0') === '1';

$pmLabel = match($order['payment_method']) {
    'cod'    => 'Cash on Delivery',
    'online' => 'Online Payment',
    default  => ucfirst($order['payment_method'])
};
$paidLabel = match($order['payment_status']) {
    'paid'        => 'PAID',
    'cod_pending' => 'PAY ON DELIVERY',
    default       => 'PENDING'
};
$paidColor = match($order['payment_status']) {
    'paid'        => '#16a34a',
    'cod_pending' => '#d97706',
    default       => '#dc2626'
};

$orderDate = date('d M Y, h:i A', strtotime($order['created_at']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>Bill #<?= htmlspecialchars($order['order_number']) ?> — <?= htmlspecialchars($restName) ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Segoe UI', system-ui, sans-serif;
    background: #f3f4f6;
    min-height: 100vh;
    padding: 16px;
}
.bill-wrap {
    max-width: 420px;
    margin: 0 auto;
}
.bill {
    background: #fff;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 4px 24px rgba(0,0,0,.10);
}

/* Header */
.bill-header {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    padding: 28px 24px 24px;
    text-align: center;
    position: relative;
}
.bill-header::after {
    content: '';
    position: absolute;
    bottom: -1px; left: 0; right: 0;
    height: 20px;
    background: #fff;
    border-radius: 20px 20px 0 0;
}
.logo-wrap { margin-bottom: 12px; }
.logo-wrap img { width: 64px; height: 64px; border-radius: 12px; object-fit: cover; }
.logo-placeholder {
    width: 64px; height: 64px; border-radius: 12px;
    background: rgba(255,255,255,.12);
    display: flex; align-items: center; justify-content: center;
    font-size: 28px; margin: 0 auto;
}
.rest-name {
    font-size: 20px; font-weight: 800; color: #fff;
    letter-spacing: -.3px;
}
.rest-tagline { font-size: 12px; color: rgba(255,255,255,.6); margin-top: 4px; }
.rest-info { font-size: 11px; color: rgba(255,255,255,.5); margin-top: 8px; line-height: 1.6; }

/* Status badge */
.status-band {
    background: #f9fafb;
    border-bottom: 1px solid #f0f0f0;
    padding: 14px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.order-no { font-size: 13px; font-weight: 700; color: #111; }
.order-date { font-size: 11px; color: #6b7280; margin-top: 2px; }
.paid-badge {
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .5px;
    background: <?= $paidColor ?>1a;
    color: <?= $paidColor ?>;
    border: 1px solid <?= $paidColor ?>33;
}

/* Customer info */
.section { padding: 18px 24px; border-bottom: 1px solid #f3f4f6; }
.section-label {
    font-size: 10px; font-weight: 700; color: #9ca3af;
    letter-spacing: 1px; text-transform: uppercase; margin-bottom: 10px;
}
.info-row { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 6px; }
.info-key { font-size: 12px; color: #6b7280; }
.info-val { font-size: 12px; font-weight: 600; color: #111; text-align: right; max-width: 200px; }

/* Items */
.items-section { padding: 18px 24px; border-bottom: 1px solid #f3f4f6; }
.item-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    padding: 10px 0;
    border-bottom: 1px dashed #f0f0f0;
    gap: 8px;
}
.item-row:last-child { border-bottom: none; }
.item-name { font-size: 13px; font-weight: 600; color: #111; flex: 1; }
.item-qty { font-size: 11px; color: #6b7280; margin-top: 2px; }
.item-price { font-size: 13px; font-weight: 700; color: #111; white-space: nowrap; }

/* Totals */
.totals { padding: 18px 24px; background: #f9fafb; }
.total-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 5px 0;
    font-size: 13px;
}
.total-row .label { color: #6b7280; }
.total-row .val   { font-weight: 600; color: #111; }
.total-row.discount .val { color: #16a34a; }
.total-row.grand {
    border-top: 2px solid #111;
    margin-top: 10px;
    padding-top: 12px;
    font-size: 16px;
    font-weight: 800;
}
.total-row.grand .label { color: #111; }
.total-row.grand .val   { color: #111; font-size: 18px; }

/* Payment method */
.payment-section {
    padding: 14px 24px;
    border-top: 1px solid #f0f0f0;
    display: flex;
    align-items: center;
    gap: 10px;
    background: #fff;
}
.pm-icon { font-size: 20px; }
.pm-label { font-size: 12px; font-weight: 600; color: #374151; }
.pm-sub   { font-size: 11px; color: #9ca3af; }

/* Footer */
.bill-footer {
    background: linear-gradient(135deg, #1a1a2e, #16213e);
    padding: 20px 24px;
    text-align: center;
}
.footer-msg { font-size: 14px; color: rgba(255,255,255,.8); font-weight: 500; }
.gstin { font-size: 10px; color: rgba(255,255,255,.4); margin-top: 6px; }

/* QR / decorative */
.dashes {
    padding: 10px 24px;
    text-align: center;
    font-size: 11px;
    letter-spacing: 4px;
    color: #d1d5db;
    border-bottom: 1px solid #f0f0f0;
}

/* Print button */
.print-btn {
    display: block;
    width: 100%;
    margin-top: 14px;
    padding: 13px;
    border-radius: 12px;
    background: #1a1a2e;
    color: #fff;
    font-size: 14px;
    font-weight: 700;
    border: none;
    cursor: pointer;
    text-align: center;
    font-family: inherit;
}
.print-btn:hover { background: #16213e; }

@media print {
    body { background: #fff; padding: 0; }
    .print-btn { display: none; }
    .bill { box-shadow: none; border-radius: 0; }
}
</style>
</head>
<body>
<div class="bill-wrap">
<div class="bill">

    <!-- Header -->
    <div class="bill-header">
        <div class="logo-wrap">
            <?php if ($logoUrl): ?>
                <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo">
            <?php else: ?>
                <div class="logo-placeholder">🍽</div>
            <?php endif; ?>
        </div>
        <div class="rest-name"><?= htmlspecialchars($restName) ?></div>
        <?php if ($tagline): ?><div class="rest-tagline"><?= htmlspecialchars($tagline) ?></div><?php endif; ?>
        <?php if ($restAddr || $restPh): ?>
        <div class="rest-info">
            <?php if ($restAddr): ?><?= htmlspecialchars($restAddr) ?><br><?php endif; ?>
            <?php if ($restPh): ?>Ph: +<?= htmlspecialchars($restPh) ?><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Order status -->
    <div class="status-band">
        <div>
            <div class="order-no">Order #<?= htmlspecialchars($order['order_number']) ?></div>
            <div class="order-date"><?= $orderDate ?></div>
        </div>
        <div class="paid-badge"><?= $paidLabel ?></div>
    </div>

    <!-- Customer info -->
    <div class="section">
        <div class="section-label">Customer Details</div>
        <?php if ($order['customer_name']): ?>
        <div class="info-row">
            <span class="info-key">Naam</span>
            <span class="info-val"><?= htmlspecialchars($order['customer_name']) ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($order['customer_phone'])): ?>
        <div class="info-row">
            <span class="info-key">Phone</span>
            <span class="info-val"><?= htmlspecialchars($order['customer_phone']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($order['delivery_address'] && $order['delivery_address'] !== 'PICKUP'): ?>
        <div class="info-row">
            <span class="info-key">Address</span>
            <span class="info-val"><?= htmlspecialchars($order['delivery_address']) ?></span>
        </div>
        <?php elseif ($order['delivery_address'] === 'PICKUP'): ?>
        <div class="info-row">
            <span class="info-key">Type</span>
            <span class="info-val">Self Pickup</span>
        </div>
        <?php endif; ?>
        <div class="info-row">
            <span class="info-key">Order Status</span>
            <span class="info-val"><?= ucfirst($order['order_status']) ?></span>
        </div>
    </div>

    <!-- Items -->
    <div class="items-section">
        <div class="section-label">Order Items</div>
        <?php foreach ($items as $item): ?>
        <div class="item-row">
            <div>
                <div class="item-name"><?= htmlspecialchars($item['name']) ?></div>
                <div class="item-qty">x<?= (int)$item['qty'] ?> @ Rs.<?= number_format($item['price'], 0) ?></div>
            </div>
            <div class="item-price">Rs.<?= number_format($item['price'] * $item['qty'], 0) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Totals -->
    <div class="totals">
        <div class="total-row">
            <span class="label">Subtotal</span>
            <span class="val">Rs.<?= number_format($order['subtotal'], 0) ?></span>
        </div>
        <?php if ($order['delivery_charge'] > 0): ?>
        <div class="total-row">
            <span class="label">Delivery Charge</span>
            <span class="val">Rs.<?= number_format($order['delivery_charge'], 0) ?></span>
        </div>
        <?php else: ?>
        <div class="total-row">
            <span class="label">Delivery</span>
            <span class="val" style="color:#16a34a">FREE</span>
        </div>
        <?php endif; ?>
        <?php if (!empty($order['gst_amount']) && $order['gst_amount'] > 0): ?>
        <div class="total-row">
            <span class="label">GST (<?= $gstPct ?>%)</span>
            <span class="val">Rs.<?= number_format($order['gst_amount'], 0) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($order['discount_amount'] > 0): ?>
        <div class="total-row discount">
            <span class="label">Discount<?= $order['coupon_code'] ? ' ('.$order['coupon_code'].')' : '' ?></span>
            <span class="val">-Rs.<?= number_format($order['discount_amount'], 0) ?></span>
        </div>
        <?php endif; ?>
        <div class="total-row grand">
            <span class="label">Total</span>
            <span class="val">Rs.<?= number_format($order['total'], 0) ?></span>
        </div>
    </div>

    <!-- Payment method -->
    <div class="payment-section">
        <span class="pm-icon"><?= $order['payment_method'] === 'cod' ? '💵' : '💳' ?></span>
        <div>
            <div class="pm-label"><?= $pmLabel ?></div>
            <div class="pm-sub"><?= $order['payment_method'] === 'cod' ? 'Cash dena hoga delivery pe' : 'Online payment received' ?></div>
        </div>
    </div>

    <?php if ($gstin): ?>
    <div class="dashes">GSTIN: <?= htmlspecialchars($gstin) ?></div>
    <?php endif; ?>

    <!-- Footer -->
    <div class="bill-footer">
        <div class="footer-msg"><?= htmlspecialchars($footer) ?></div>
        <?php if ($gstin): ?><div class="gstin">GSTIN: <?= htmlspecialchars($gstin) ?></div><?php endif; ?>
    </div>

</div>

<button class="print-btn" onclick="window.print()">🖨 Print / Save as PDF</button>
</div>
</body>
</html>
