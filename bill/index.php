<?php
// ============================================
//  DIGITAL BILL — bill/index.php
//  Customer link: yourdomain.com/food-bot/bill/?t=TOKEN
// ============================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';

$token = trim($_GET['t'] ?? '');
if (!$token) { http_response_code(404); die("Bill nahi mili."); }

$db   = getDB();
$stmt = $db->prepare("SELECT * FROM orders WHERE bill_token = ?");
$stmt->execute([$token]);
$order = $stmt->fetch();

if (!$order) { http_response_code(404); die("Bill nahi mili ya link expired hai."); }

// Mark as viewed
if (!$order['bill_viewed_at']) {
    $db->prepare("UPDATE orders SET bill_viewed_at = NOW() WHERE id = ?")->execute([$order['id']]);
}

$items       = json_decode($order['items'], true);
$restName    = getSetting('restaurant_name', 'Restaurant');
$restPhone   = getSetting('restaurant_phone', '');
$restAddress = getSetting('restaurant_address', '');
$gstPct      = getSetting('gst_percent', '5');
$gstEnabled  = getSetting('gst_enabled', '0') === '1';
$orderDate   = date('d M Y, h:i A', strtotime($order['created_at']));
$payMethod   = $order['payment_method'] === 'cod' ? 'Cash on Delivery' : 'Online Payment';
$payStatus   = match($order['payment_status']) {
    'paid'        => ['label' => 'PAID', 'color' => '#16a34a'],
    'cod_pending' => ['label' => 'COD', 'color' => '#d97706'],
    default       => ['label' => 'PENDING', 'color' => '#dc2626'],
};
?>
<!DOCTYPE html>
<html lang="pa">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bill #<?= htmlspecialchars($order['order_number']) ?> — <?= htmlspecialchars($restName) ?></title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f1f5f9; min-height: 100vh; padding: 20px; }

  .bill-wrap { max-width: 480px; margin: 0 auto; }

  /* Print button */
  .print-bar { display: flex; gap: 10px; margin-bottom: 16px; justify-content: flex-end; }
  .print-btn { display: flex; align-items: center; gap: 6px; padding: 9px 18px; border-radius: 8px; border: none; font-size: 13px; font-weight: 700; cursor: pointer; font-family: inherit; }
  .btn-print  { background: #1e293b; color: #fff; }
  .btn-wa     { background: #25D366; color: #fff; }

  /* Bill Card */
  .bill { background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,.10); }

  /* Header */
  .bill-header { background: linear-gradient(135deg, #1e293b 0%, #334155 100%); color: #fff; padding: 28px 24px 20px; text-align: center; position: relative; }
  .bill-header::after { content: ''; display: block; position: absolute; bottom: -1px; left: 0; right: 0; height: 20px; background: #fff; border-radius: 50% 50% 0 0 / 20px 20px 0 0; }
  .rest-name { font-size: 22px; font-weight: 800; letter-spacing: -.3px; }
  .rest-meta  { font-size: 12px; opacity: .75; margin-top: 4px; line-height: 1.6; }
  .bill-badge { display: inline-flex; align-items: center; gap: 6px; margin-top: 14px; background: rgba(255,255,255,.12); padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 700; letter-spacing: .5px; }

  /* Order Info */
  .order-info { padding: 22px 24px 0; }
  .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  .info-item label { font-size: 10px; font-weight: 700; color: #94a3b8; letter-spacing: .8px; text-transform: uppercase; display: block; margin-bottom: 3px; }
  .info-item span  { font-size: 13px; font-weight: 600; color: #1e293b; }
  .pay-status { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 800; letter-spacing: .5px; }

  /* Divider */
  .divider { margin: 20px 24px; border: none; border-top: 1px dashed #e2e8f0; }

  /* Items */
  .items-section { padding: 0 24px; }
  .items-head { display: flex; justify-content: space-between; font-size: 10px; font-weight: 700; color: #94a3b8; letter-spacing: .8px; text-transform: uppercase; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9; }
  .item-row { display: flex; align-items: flex-start; justify-content: space-between; padding: 11px 0; border-bottom: 1px solid #f8fafc; }
  .item-row:last-child { border-bottom: none; }
  .item-name  { font-size: 14px; font-weight: 600; color: #1e293b; }
  .item-qty   { font-size: 12px; color: #64748b; margin-top: 2px; }
  .item-price { font-size: 14px; font-weight: 700; color: #1e293b; white-space: nowrap; }

  /* Totals */
  .totals { background: #f8fafc; margin: 0 24px 24px; border-radius: 10px; padding: 16px; }
  .total-row { display: flex; justify-content: space-between; align-items: center; font-size: 13px; padding: 4px 0; }
  .total-row .lbl { color: #64748b; }
  .total-row .val { font-weight: 600; color: #1e293b; }
  .total-row.discount .val { color: #16a34a; }
  .total-row.grand { padding-top: 12px; margin-top: 8px; border-top: 2px solid #e2e8f0; }
  .total-row.grand .lbl { font-size: 15px; font-weight: 800; color: #1e293b; }
  .total-row.grand .val { font-size: 18px; font-weight: 800; color: #1e293b; }

  /* Footer */
  .bill-footer { text-align: center; padding: 16px 24px 24px; }
  .thank-you { font-size: 16px; font-weight: 800; color: #1e293b; }
  .footer-sub { font-size: 12px; color: #94a3b8; margin-top: 4px; line-height: 1.6; }
  .qr-note { display: inline-block; margin-top: 12px; background: #f1f5f9; padding: 8px 16px; border-radius: 8px; font-size: 11px; color: #64748b; }

  /* Watermark for unpaid */
  <?php if ($order['payment_status'] === 'pending'): ?>
  .bill::after { content: 'UNPAID'; position: fixed; top: 50%; left: 50%; transform: translate(-50%,-50%) rotate(-30deg); font-size: 80px; font-weight: 900; color: rgba(220,38,38,.08); pointer-events: none; z-index: 0; letter-spacing: 8px; }
  <?php endif; ?>

  /* Print styles */
  @media print {
    body { background: #fff; padding: 0; }
    .print-bar { display: none; }
    .bill { box-shadow: none; border-radius: 0; }
  }
</style>
</head>
<body>

<div class="bill-wrap">

  <!-- Action buttons -->
  <div class="print-bar">
    <button class="print-btn btn-print" onclick="window.print()">🖨️ Print</button>
    <button class="print-btn btn-wa" onclick="shareWhatsApp()">📤 Share</button>
  </div>

  <div class="bill">

    <!-- Header -->
    <div class="bill-header">
      <div class="rest-name"><?= htmlspecialchars($restName) ?></div>
      <div class="rest-meta">
        <?php if ($restAddress): ?><?= htmlspecialchars($restAddress) ?><br><?php endif; ?>
        <?php if ($restPhone): ?>+<?= htmlspecialchars($restPhone) ?><?php endif; ?>
      </div>
      <div class="bill-badge">BILL / RECEIPT</div>
    </div>

    <!-- Order Info -->
    <div class="order-info">
      <div class="info-grid">
        <div class="info-item">
          <label>Order Number</label>
          <span>#<?= htmlspecialchars($order['order_number']) ?></span>
        </div>
        <div class="info-item">
          <label>Payment Status</label>
          <span class="pay-status" style="background:<?= $payStatus['color'] ?>22;color:<?= $payStatus['color'] ?>">
            <?= $payStatus['label'] ?>
          </span>
        </div>
        <div class="info-item">
          <label>Date & Time</label>
          <span><?= $orderDate ?></span>
        </div>
        <div class="info-item">
          <label>Payment Method</label>
          <span><?= $payMethod ?></span>
        </div>
        <?php if (!empty($order['customer_name'])): ?>
        <div class="info-item">
          <label>Customer</label>
          <span><?= htmlspecialchars($order['customer_name']) ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($order['delivery_address']) && $order['delivery_address'] !== 'PICKUP'): ?>
        <div class="info-item">
          <label>Delivery Address</label>
          <span><?= htmlspecialchars($order['delivery_address']) ?></span>
        </div>
        <?php elseif ($order['delivery_address'] === 'PICKUP'): ?>
        <div class="info-item">
          <label>Order Type</label>
          <span>Self Pickup</span>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <hr class="divider">

    <!-- Items -->
    <div class="items-section">
      <div class="items-head">
        <span>Item</span>
        <span>Amount</span>
      </div>
      <?php foreach ($items as $item): $subtotal = $item['price'] * $item['qty']; ?>
      <div class="item-row">
        <div>
          <div class="item-name"><?= htmlspecialchars($item['name']) ?></div>
          <div class="item-qty">Rs.<?= number_format($item['price'], 0) ?> × <?= $item['qty'] ?></div>
        </div>
        <div class="item-price">Rs.<?= number_format($subtotal, 0) ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <hr class="divider">

    <!-- Totals -->
    <div class="totals">
      <div class="total-row">
        <span class="lbl">Subtotal</span>
        <span class="val">Rs.<?= number_format($order['subtotal'], 2) ?></span>
      </div>
      <?php if (!empty($order['gst_amount']) && $order['gst_amount'] > 0): ?>
      <div class="total-row">
        <span class="lbl">GST (<?= $gstPct ?>%)</span>
        <span class="val">Rs.<?= number_format($order['gst_amount'], 2) ?></span>
      </div>
      <?php endif; ?>
      <?php if ($order['delivery_charge'] > 0): ?>
      <div class="total-row">
        <span class="lbl">Delivery Charge</span>
        <span class="val">Rs.<?= number_format($order['delivery_charge'], 2) ?></span>
      </div>
      <?php elseif (isset($order['delivery_charge'])): ?>
      <div class="total-row">
        <span class="lbl">Delivery</span>
        <span class="val" style="color:#16a34a">FREE</span>
      </div>
      <?php endif; ?>
      <?php if ($order['discount_amount'] > 0): ?>
      <div class="total-row discount">
        <span class="lbl">Discount <?= $order['coupon_code'] ? '('.$order['coupon_code'].')' : '' ?></span>
        <span class="val">- Rs.<?= number_format($order['discount_amount'], 2) ?></span>
      </div>
      <?php endif; ?>
      <div class="total-row grand">
        <span class="lbl">Total Amount</span>
        <span class="val">Rs.<?= number_format($order['total'], 2) ?></span>
      </div>
    </div>

    <!-- Footer -->
    <div class="bill-footer">
      <div class="thank-you">Shukriya ji! 🙏</div>
      <div class="footer-sub">
        <?= htmlspecialchars($restName) ?> te dobara aao<br>
        Koi problem? Call karo: +<?= htmlspecialchars($restPhone) ?>
      </div>
      <div class="qr-note">Bill ID: <?= htmlspecialchars($order['order_number']) ?></div>
    </div>

  </div><!-- .bill -->
</div><!-- .bill-wrap -->

<script>
function shareWhatsApp() {
    const url  = encodeURIComponent(window.location.href);
    const text = encodeURIComponent('Tera bill dekho: ');
    window.open('https://wa.me/?text=' + text + url, '_blank');
}
</script>
</body>
</html>
