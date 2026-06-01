<?php
// ============================================
//  RAZORPAY WEBHOOK — razorpay-webhook.php
//  Razorpay iss file ko call karda hai jado
//  payment complete hundi hai
// ============================================

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/helpers.php';

$payload   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

// Verify signature — sirf agar secret set hai
$webhookSecret = rzpWebhook();
if (!empty($webhookSecret) && !empty($signature)) {
    $expectedSig = hash_hmac('sha256', $payload, $webhookSecret);
    if (!hash_equals($expectedSig, $signature)) {
        http_response_code(400);
        error_log("Razorpay webhook: Invalid signature");
        echo "Invalid signature";
        exit;
    }
}

$event = json_decode($payload, true);
$type  = $event['event'] ?? '';

// Payment success
if ($type === 'payment_link.paid') {
    $paymentId  = $event['payload']['payment']['entity']['id']           ?? '';
    $linkId     = $event['payload']['payment_link']['entity']['id']      ?? '';
    $orderNotes = $event['payload']['payment_link']['entity']['notes']   ?? [];
    $orderNum   = $orderNotes['order_number'] ?? '';
    $orderId    = $orderNotes['order_id']     ?? '';

    if ($orderNum && $orderId) {
        $db = getDB();

        // Update order status
        $db->prepare("UPDATE orders SET payment_status='paid', order_status='confirmed', razorpay_payment_id=? WHERE id=?")
           ->execute([$paymentId, $orderId]);

        // Get order details
        $stmt = $db->prepare("SELECT * FROM orders WHERE id=?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if ($order) {
            $phone    = $order['phone'];
            $name     = $order['customer_name'];
            $total    = $order['total'];
            $cart     = json_decode($order['items'], true);
            $estTime  = getSetting('estimated_time', '30-45');
            $restName = getSetting('restaurant_name', RESTAURANT_NAME);

            // ---- Customer nu confirmation ----
            $msg  = "🎉 *Payment ho gaya! Order confirmed!*\n\n";
            $msg .= "📋 *Order #$orderNum*\n";
            $msg .= "👤 *$name* ji\n\n";
            foreach ($cart as $item) {
                $addonSum = 0;
                if (!empty($item['addons'])) foreach ($item['addons'] as $a) $addonSum += $a['price'];
                $itemTotal = ($item['price'] + $addonSum) * $item['qty'];
                $msg .= "• {$item['name']} x{$item['qty']} = ₹{$itemTotal}\n";
                if (!empty($item['addons'])) {
                    foreach ($item['addons'] as $a) $msg .= "  ➕ {$a['name']}\n";
                }
            }
            $msg .= "\n💰 *Total Paid: ₹$total*\n";
            $msg .= "⏱ *{$estTime} minutes* mein milega\n\n";
            $msg .= "Khaana ready hone te dobara inform karenge 😊\n";
            $msg .= "Shukriya *{$restName}* choose karne da! 🙏";
            sendWhatsApp($phone, $msg);

            // ---- Bill link bhejo ----
            $billToken = generateBillToken($orderId);
            $billUrl   = getBillUrl($billToken);
            sendWhatsApp($phone, "🧾 *Apna bill ready hai ji!*\n\n" . $billUrl . "\n\n_Print/Save laye Bill page te Print button use karo_");

            // ---- Restaurant nu order notification ----
            $address = $order['delivery_address'] ?? 'N/A';
            $rmsg  = "✅ *PAYMENT CONFIRMED — Order #$orderNum*\n\n";
            $rmsg .= "👤 $name | 📞 +$phone\n";
            $rmsg .= "📍 $address\n\n";
            foreach ($cart as $item) {
                $subtotal = $item['price'] * $item['qty'];
                $rmsg .= "• {$item['name']} x{$item['qty']} = ₹$subtotal\n";
            }
            $rmsg .= "\n💰 *Total Received: ₹$total*\n";
            $rmsg .= "🆔 Payment ID: $paymentId";
            sendWhatsApp(restPhone(), $rmsg);

            // Update session state
            updateSession($phone, ['state' => 'CATEGORY_SELECT', 'cart' => '[]']);
        }
    }
}

// Payment failed/expired
if (in_array($type, ['payment_link.expired', 'payment.failed'])) {
    $orderNotes = $event['payload']['payment_link']['entity']['notes'] ?? [];
    $orderId    = $orderNotes['order_id'] ?? '';
    $orderNum   = $orderNotes['order_number'] ?? '';

    if ($orderId) {
        $db = getDB();
        $db->prepare("UPDATE orders SET payment_status='failed' WHERE id=?")
           ->execute([$orderId]);

        $stmt = $db->prepare("SELECT phone FROM orders WHERE id=?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if ($order) {
            $msg  = "⚠️ *Order #$orderNum da payment nahi aaya.*\n\n";
            $msg .= "Dobara try karne liye *menu* type karo.\n";
            $msg .= "Koi problem hai? Call karo: +" . restPhone();
            sendWhatsApp($order['phone'], $msg);
            updateSession($order['phone'], ['state' => 'CATEGORY_SELECT']);
        }
    }
}

http_response_code(200);
echo "ok";
