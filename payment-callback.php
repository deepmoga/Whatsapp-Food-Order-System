<?php
// ============================================
//  PAYMENT CALLBACK — payment-callback.php
//  Razorpay customer nu yahan redirect karda
//  payment ke baad (GET request)
//  Backup: order status update karo agar
//  webhook nahi aaya
// ============================================

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/helpers.php';

$razorpayPaymentId  = $_GET['razorpay_payment_id']   ?? '';
$razorpayPaymentLink= $_GET['razorpay_payment_link_id'] ?? '';
$razorpayStatus     = $_GET['razorpay_payment_link_status'] ?? '';

$db = getDB();

// Payment successful
if ($razorpayStatus === 'paid' && $razorpayPaymentId) {

    // Find order by payment link ID
    $stmt = $db->prepare("SELECT * FROM orders WHERE razorpay_order_id=? LIMIT 1");
    $stmt->execute([$razorpayPaymentLink]);
    $order = $stmt->fetch();

    if ($order && $order['payment_status'] !== 'paid') {
        // Update payment status
        $db->prepare("UPDATE orders SET payment_status='paid', order_status='confirmed', razorpay_payment_id=?, updated_at=NOW() WHERE id=?")
           ->execute([$razorpayPaymentId, $order['id']]);

        $phone    = $order['phone'];
        $name     = $order['customer_name'];
        $total    = $order['total'];
        $cart     = json_decode($order['items'], true);
        $estTime  = getSetting('estimated_time', '30-45');
        $restName = getSetting('restaurant_name', 'Restaurant');

        // Customer nu confirmation + bill
        $msg  = "🎉 *Payment ho gaya! Order confirmed!*\n\n";
        $msg .= "📋 *Order #{$order['order_number']}*\n";
        $msg .= "👤 *{$name}* ji\n\n";
        foreach ($cart as $item) {
            $addonSum = 0;
            if (!empty($item['addons'])) foreach ($item['addons'] as $a) $addonSum += $a['price'];
            $itemTotal = ($item['price'] + $addonSum) * $item['qty'];
            $msg .= "• {$item['name']} x{$item['qty']} = ₹{$itemTotal}\n";
        }
        $msg .= "\n💰 *Total Paid: ₹{$total}*\n";
        $msg .= "⏱ *{$estTime} minutes* mein milega\n\n";
        $msg .= "Shukriya *{$restName}* choose karne da! 🙏";
        sendWhatsApp($phone, $msg);

        // Bill link
        $billToken = generateBillToken($order['id']);
        $billUrl   = getBillUrl($billToken);
        sendWhatsApp($phone, "🧾 *Apna bill ready hai ji!*\n\n" . $billUrl . "\n\n_Print/Save laye Print button use karo_");

        // Session reset
        updateSession($phone, ['state' => 'CATEGORY_SELECT', 'cart' => '[]']);

        // Restaurant notify
        $restPhone = restPhone();
        if ($restPhone) {
            $rmsg  = "✅ *PAYMENT CONFIRMED — Order #{$order['order_number']}*\n";
            $rmsg .= "👤 {$name} | ₹{$total}\n";
            $rmsg .= "🆔 Payment: {$razorpayPaymentId}";
            sendWhatsApp($restPhone, $rmsg);
        }
    }
}

// Customer nu success page dikhao
$orderNum = $order['order_number'] ?? 'N/A';
$restName = getSetting('restaurant_name', 'Restaurant');
?>
<!DOCTYPE html>
<html lang="pa">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Payment Done — <?= htmlspecialchars($restName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Plus Jakarta Sans',sans-serif;background:#f0fdf4;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:#fff;border-radius:20px;padding:40px 32px;max-width:400px;width:100%;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.08)}
.icon{font-size:64px;margin-bottom:16px}
h1{font-size:24px;font-weight:800;color:#16a34a;margin-bottom:8px}
p{font-size:14px;color:#6b7280;line-height:1.6;margin-bottom:6px}
.order-num{font-size:18px;font-weight:700;color:#111;background:#f0fdf4;padding:10px 20px;border-radius:10px;margin:16px 0;display:inline-block}
.wa-btn{display:inline-flex;align-items:center;gap:8px;background:#25d366;color:#fff;padding:12px 24px;border-radius:10px;text-decoration:none;font-weight:700;font-size:14px;margin-top:20px}
</style>
</head>
<body>
<div class="card">
  <div class="icon">🎉</div>
  <h1>Payment Ho Gaya!</h1>
  <?php if ($razorpayStatus === 'paid'): ?>
    <p>Tera order confirm ho gaya hai ji</p>
    <div class="order-num">Order #<?= htmlspecialchars($orderNum) ?></div>
    <p>WhatsApp te bill aur confirmation message check karo</p>
  <?php else: ?>
    <p style="color:#dc2626">Payment complete nahi hui.<br>Dobara try karo ji.</p>
  <?php endif; ?>
  <br>
  <a href="https://wa.me/<?= htmlspecialchars(getSetting('restaurant_phone','')) ?>" class="wa-btn">
    💬 WhatsApp te Message Karo
  </a>
</div>
</body>
</html>
