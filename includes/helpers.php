<?php
require_once __DIR__ . '/../config/config.php';

// ============================================
//  SETTINGS — DB se load, constants fallback
// ============================================
function getSetting($key, $default = '') {
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    try {
        $stmt = getDB()->prepare("SELECT setting_value FROM settings WHERE setting_key=?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $cache[$key] = ($row !== false) ? $row['setting_value'] : $default;
    } catch (Exception $e) {
        $cache[$key] = $default;
    }
    return $cache[$key];
}

// Dynamic getters — always from DB
function waToken()    { return getSetting('whatsapp_token',          WHATSAPP_TOKEN); }
function waPhoneId()  { return getSetting('whatsapp_phone_id',       WHATSAPP_PHONE_ID); }
function verifyTok()  { return getSetting('verify_token',            VERIFY_TOKEN); }
function rzpKeyId()   { return getSetting('razorpay_key_id',         RAZORPAY_KEY_ID); }
function rzpSecret()  { return getSetting('razorpay_key_secret',     RAZORPAY_KEY_SECRET); }
function rzpWebhook() { return getSetting('razorpay_webhook_secret',  RAZORPAY_WEBHOOK_SECRET); }
function baseUrl()    { return rtrim(getSetting('base_url',           BASE_URL), '/'); }
function restPhone()  { return getSetting('restaurant_phone',         RESTAURANT_PHONE); }

// ============================================
//  GST CALCULATOR
// ============================================
function calculateGST($subtotal) {
    if (getSetting('gst_enabled', '0') !== '1') return 0;
    $pct = (float)getSetting('gst_percent', 5);
    if (getSetting('gst_included', '0') === '1') {
        // GST already in price — extract it
        return round($subtotal - ($subtotal * 100 / (100 + $pct)), 2);
    }
    return round($subtotal * $pct / 100, 2);
}

function orderBreakdown($cart, $discount = 0, $deliveryCharge = 0) {
    $subtotal = cartTotal($cart);
    $gst      = calculateGST($subtotal);
    $gstIncluded = getSetting('gst_included', '0') === '1';
    $total    = $gstIncluded
        ? $subtotal + $deliveryCharge - $discount
        : $subtotal + $gst + $deliveryCharge - $discount;
    return [
        'subtotal'     => $subtotal,
        'gst'          => $gst,
        'gst_included' => $gstIncluded,
        'delivery'     => $deliveryCharge,
        'discount'     => $discount,
        'total'        => max(0, $total),
    ];
}

// ============================================
//  WHATSAPP SENDERS
// ============================================
function sendWhatsApp($to, $message) {
    $data = [
        "messaging_product" => "whatsapp",
        "recipient_type"    => "individual",
        "to"                => $to,
        "type"              => "text",
        "text"              => ["preview_url" => false, "body" => $message]
    ];
    return _waPost($to, $data, $message);
}

function sendButtonMessage($to, $body, $buttons, $header = '', $footer = '') {
    $btnList = [];
    foreach ($buttons as $id => $label) {
        $btnList[] = ["type" => "reply", "reply" => ["id" => $id, "title" => mb_substr($label, 0, 20)]];
    }
    $interactive = ["type" => "button", "body" => ["text" => $body], "action" => ["buttons" => $btnList]];
    if ($header) $interactive["header"] = ["type" => "text", "text" => $header];
    if ($footer) $interactive["footer"] = ["text" => $footer];
    $data = [
        "messaging_product" => "whatsapp",
        "recipient_type"    => "individual",
        "to"                => $to,
        "type"              => "interactive",
        "interactive"       => $interactive
    ];
    return _waPost($to, $data, $body);
}

function sendListMessage($to, $header, $body, $footer, $buttonLabel, $sections) {
    $data = [
        "messaging_product" => "whatsapp",
        "recipient_type"    => "individual",
        "to"                => $to,
        "type"              => "interactive",
        "interactive"       => [
            "type"   => "list",
            "header" => ["type" => "text", "text" => $header],
            "body"   => ["text" => $body],
            "footer" => ["text" => $footer],
            "action" => ["button" => $buttonLabel, "sections" => $sections]
        ]
    ];
    return _waPost($to, $data, $body);
}

function _waPost($to, $data, $logMsg) {
    $phoneId = waPhoneId();
    $token   = waToken();
    if (!$phoneId || !$token) {
        error_log("WA: Phone ID or Token missing in settings");
        return null;
    }
    $ch = curl_init("https://graph.facebook.com/v19.0/{$phoneId}/messages");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$token}",
            "Content-Type: application/json"
        ]
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);
    logMessage($to, 'out', mb_substr($logMsg, 0, 500));
    if ($err) error_log("WA curl error: $err");
    $res = json_decode($response, true);
    if (isset($res['error'])) error_log("WA API error: " . json_encode($res['error']));
    return $res;
}

// ============================================
//  SESSION MANAGEMENT
// ============================================
function getSession($phone) {
    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM sessions WHERE phone = ?");
    $stmt->execute([$phone]);
    $session = $stmt->fetch();
    if (!$session) {
        $db->prepare("INSERT INTO sessions (phone, state, cart) VALUES (?, 'WELCOME', '[]')")->execute([$phone]);
        return ['phone' => $phone, 'state' => 'WELCOME', 'cart' => '[]',
                'selected_category' => null, 'customer_name' => '',
                'customer_phone' => null, 'pending_item_id' => null,
                'pending_coupon' => null, 'pending_discount' => 0,
                'delivery_charge' => 0, 'payment_method' => 'online'];
    }
    return $session;
}

function updateSession($phone, $data) {
    $db = getDB(); $fields = []; $values = [];
    foreach ($data as $key => $val) { $fields[] = "$key = ?"; $values[] = $val; }
    $values[] = $phone;
    $db->prepare("UPDATE sessions SET " . implode(', ', $fields) . " WHERE phone = ?")->execute($values);
}

function getCart($phone) {
    $session = getSession($phone);
    return json_decode($session['cart'] ?? '[]', true) ?: [];
}

function saveCart($phone, $cart) {
    updateSession($phone, ['cart' => json_encode(array_values($cart))]);
}

function cartTotal($cart) {
    $total = 0;
    foreach ($cart as $item) {
        $addonSum = 0;
        if (!empty($item['addons'])) {
            foreach ($item['addons'] as $a) { $addonSum += $a['price']; }
        }
        $total += ($item['price'] + $addonSum) * $item['qty'];
    }
    return $total;
}

function cartSummary($cart, $discount = 0, $deliveryCharge = 0) {
    if (empty($cart)) return "Tera cart khali hai.";
    $bd    = orderBreakdown($cart, $discount, $deliveryCharge);
    $lines = ["*Tera Order:*\n"];
    $i = 1;
    foreach ($cart as $item) {
        $itemTotal = $item['price'] * $item['qty'];
        // Add addon prices to item total
        $addonTotal = 0;
        if (!empty($item['addons'])) {
            foreach ($item['addons'] as $a) { $addonTotal += $a['price']; }
            $itemTotal = ($item['price'] + $addonTotal) * $item['qty'];
        }
        $lines[] = "{$i}. {$item['name']} x{$item['qty']} = Rs." . $itemTotal;
        if (!empty($item['addons'])) {
            foreach ($item['addons'] as $a) {
                $lines[] = "   ➕ {$a['name']}" . ($a['price'] > 0 ? " (+Rs.{$a['price']})" : "");
            }
        }
        $i++;
    }
    $lines[] = "\nSubtotal: Rs.{$bd['subtotal']}";
    if ($bd['delivery'] > 0) $lines[] = "Delivery: Rs.{$bd['delivery']}";
    else                     $lines[] = "Delivery: FREE";
    if ($bd['gst'] > 0) {
        $pct     = getSetting('gst_percent', 5);
        $inc     = $bd['gst_included'] ? ' (included)' : '';
        $lines[] = "GST ({$pct}%){$inc}: Rs.{$bd['gst']}";
    }
    if ($bd['discount'] > 0) $lines[] = "Discount: -Rs.{$bd['discount']}";
    $lines[] = "\n*Total: Rs.{$bd['total']}*";
    return implode("\n", $lines);
}

// ============================================
//  DELIVERY CHARGE
// ============================================
function calculateDeliveryCharge($subtotal) {
    $freeAbove = (float)getSetting('free_delivery_above', 500);
    $charge    = (float)getSetting('delivery_charge', 50);
    if ($freeAbove > 0 && $subtotal >= $freeAbove) return 0;
    return $charge;
}

// ============================================
//  DISTANCE CHECK
// ============================================
function getDistanceKm($lat1, $lng1, $lat2, $lng2) {
    $R    = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a    = sin($dLat/2)*sin($dLat/2) + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLng/2)*sin($dLng/2);
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}

function geocodeAddress($address) {
    $apiKey = getSetting('google_maps_key', '');
    if (!$apiKey) return null;
    $url = "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode($address) . "&key=" . $apiKey;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);
    if (($response['status'] ?? '') === 'OK') {
        $loc = $response['results'][0]['geometry']['location'];
        return ['lat' => $loc['lat'], 'lng' => $loc['lng']];
    }
    return null;
}

function checkDeliveryArea($address) {
    $restLat = (float)getSetting('restaurant_lat', 0);
    $restLng = (float)getSetting('restaurant_lng', 0);
    $maxKm   = (float)getSetting('service_radius_km', 5);
    if (!$restLat || !$restLng) return ['ok' => true, 'distance' => 0];
    $coords = geocodeAddress($address . ", " . getSetting('restaurant_address', ''));
    if (!$coords) return ['ok' => true, 'distance' => 0];
    $dist = getDistanceKm($restLat, $restLng, $coords['lat'], $coords['lng']);
    return ['ok' => $dist <= $maxKm, 'distance' => round($dist, 1), 'lat' => $coords['lat'], 'lng' => $coords['lng']];
}

// ============================================
//  COUPON VALIDATION
// ============================================
function validateCoupon($code, $phone, $subtotal) {
    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM coupons WHERE code=? AND is_active=1");
    $stmt->execute([strtoupper($code)]);
    $coupon = $stmt->fetch();
    if (!$coupon) return ['valid' => false, 'msg' => "Coupon *{$code}* valid nahi hai."];
    if ($coupon['expires_at'] && $coupon['expires_at'] < date('Y-m-d')) return ['valid' => false, 'msg' => "Coupon expired ho gaya hai."];
    if ($coupon['usage_limit'] > 0 && $coupon['used_count'] >= $coupon['usage_limit']) return ['valid' => false, 'msg' => "Coupon limit khatam ho gayi."];
    if ($subtotal < $coupon['min_order']) return ['valid' => false, 'msg' => "Min order Rs.{$coupon['min_order']} chahida is coupon liye."];
    if ($coupon['per_user_limit'] > 0) {
        $used = $db->prepare("SELECT COUNT(*) as cnt FROM coupon_usage WHERE coupon_id=? AND phone=?");
        $used->execute([$coupon['id'], $phone]);
        if ($used->fetch()['cnt'] >= $coupon['per_user_limit']) return ['valid' => false, 'msg' => "Tusi is coupon nu pehle use kar chuke ho."];
    }
    $discount = ($coupon['type'] === 'flat')
        ? $coupon['value']
        : min($subtotal, ($coupon['max_discount'] > 0 ? min($coupon['max_discount'], $subtotal * $coupon['value'] / 100) : $subtotal * $coupon['value'] / 100));
    return ['valid' => true, 'discount' => round($discount, 2), 'coupon' => $coupon, 'msg' => "Coupon applied! Rs.{$discount} off."];
}

function applyCouponUsage($couponId, $phone, $orderId, $discount) {
    getDB()->prepare("INSERT INTO coupon_usage (coupon_id, phone, order_id, discount_amount) VALUES (?,?,?,?)")->execute([$couponId, $phone, $orderId, $discount]);
    getDB()->prepare("UPDATE coupons SET used_count = used_count + 1 WHERE id=?")->execute([$couponId]);
}

// ============================================
//  MENU
// ============================================
function getCategories() {
    return getDB()->query("SELECT * FROM categories WHERE is_active=1 ORDER BY sort_order, id")->fetchAll();
}

function getCategoryItems($categoryId) {
    $stmt = getDB()->prepare("SELECT * FROM menu_items WHERE category_id=? AND is_available=1 ORDER BY id");
    $stmt->execute([$categoryId]);
    return $stmt->fetchAll();
}

function sendCategoryMenu($phone) {
    $cats = getCategories();
    $rows = [];
    foreach ($cats as $c) {
        $rows[] = ["id" => "cat_" . $c['id'], "title" => $c['emoji'] . " " . $c['name'], "description" => ""];
    }
    sendListMessage($phone,
        getSetting('restaurant_name', 'Restaurant'),
        "Sat Sri Akal ji! Kya khana chahoge?\nCategory choose karo:",
        "Tap to select",
        "Menu Dekho",
        [["title" => "Menu Categories", "rows" => $rows]]
    );
}

// ============================================
//  ECOMMERCE STYLE — Send items with qty selector
//  Customer ek message mein multiple items + qty type kar sakda hai
//  Format shown clearly in message
// ============================================
function sendItemsMenu($phone, $categoryId) {
    $items = getCategoryItems($categoryId);
    if (empty($items)) { sendWhatsApp($phone, "Is category mein abhi koi item nahi."); return; }

    $db  = getDB();
    $cat = $db->prepare("SELECT * FROM categories WHERE id=?");
    $cat->execute([$categoryId]);
    $catRow = $cat->fetch();

    updateSession($phone, ['state' => 'ITEM_SELECT', 'selected_category' => $categoryId]);

    // WhatsApp supports max 10 items per section in list message
    $chunks = array_chunk($items, 10);

    // Single chunk — use list message (popup style like category)
    if (count($chunks) === 1) {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = [
                "id"          => "item_" . $item['id'],
                "title"       => mb_substr($item['name'], 0, 24),
                "description" => "Rs." . number_format($item['price'], 0) .
                                 ($item['description'] ? " — " . mb_substr($item['description'], 0, 40) : "")
            ];
        }
        sendListMessage(
            $phone,
            ($catRow['emoji'] ?? "") . " " . $catRow['name'],
            "Item choose karo tap karke:
(Qty baad mein set kar sakte ho)",
            "Wapas: menu type karo",
            "Item Choose Karo",
            [["title" => $catRow['name'], "rows" => $rows]]
        );
    } else {
        // More than 10 items — split into multiple sections
        $sections = [];
        foreach ($chunks as $idx => $chunk) {
            $rows = [];
            foreach ($chunk as $item) {
                $rows[] = [
                    "id"          => "item_" . $item['id'],
                    "title"       => mb_substr($item['name'], 0, 24),
                    "description" => "Rs." . number_format($item['price'], 0)
                ];
            }
            $sections[] = ["title" => $catRow['name'] . " (" . ($idx+1) . ")", "rows" => $rows];
        }
        sendListMessage(
            $phone,
            ($catRow['emoji'] ?? "") . " " . $catRow['name'],
            "Item choose karo:",
            "Wapas: menu type karo",
            "Item Choose Karo",
            $sections
        );
    }
}

function sendQtyButtons($phone, $itemId, $itemName, $price) {
    sendButtonMessage($phone,
        "*{$itemName}*\nRs.{$price} per plate\n\nKitni qty chahidi?",
        ['qty_1' => '1 Plate', 'qty_2' => '2 Plates', 'qty_3' => '3 Plates'],
        "Quantity", "Zyada: type karo e.g. 5"
    );
}

function sendCartWithButtons($phone) {
    $cart     = getCart($phone);
    $session  = getSession($phone);
    $discount = (float)($session['pending_discount'] ?? 0);
    $delivery = (float)($session['delivery_charge']  ?? 0);
    if (empty($cart)) { sendCategoryMenu($phone); return; }
    sendButtonMessage($phone,
        cartSummary($cart, $discount, $delivery) . "\n\nKya karna hai?",
        ['btn_confirm' => 'Confirm Order', 'btn_addmore' => 'Aur Add Karo', 'btn_editcart' => 'Edit Cart'],
        "Tera Cart", "Min order Rs." . getSetting('min_order_amount', 100)
    );
}

function buildEditCartMenu($cart) {
    if (empty($cart)) return "Cart khali hai.";
    $msg = "*Cart Edit:*\n\n";
    $i = 1;
    foreach ($cart as $item) {
        $msg .= "{$i}. {$item['name']} x{$item['qty']} = Rs." . ($item['price'] * $item['qty']) . "\n";
        $i++;
    }
    $msg .= "\n*Commands:*\n";
    $msg .= "- remove 1  (1st item hatao)\n";
    $msg .= "- qty 1 3   (1st item = 3 qty)\n";
    $msg .= "- done      (editing khatam)\n";
    $msg .= "- clear     (cart saaf)";
    return $msg;
}

// ============================================
//  PARSE ECOMMERCE ORDER FORMAT
//  "5:2 9:1 12:3" → [{id:5,qty:2},{id:9,qty:1}...]
// ============================================
function parseOrderFormat($text) {
    $pairs  = preg_split('/\s+/', trim($text));
    $result = [];
    foreach ($pairs as $pair) {
        if (preg_match('/^(\d+):(\d+)$/', $pair, $m)) {
            $result[] = ['id' => (int)$m[1], 'qty' => min(20, max(1, (int)$m[2]))];
        }
    }
    return $result;
}

// ============================================
//  ORDER CREATION
// ============================================
function generateOrderNumber() {
    return 'ORD' . strtoupper(substr(md5(uniqid()), 0, 6)) . date('Hm');
}

function createOrder($phone, $name, $cart, $extra = []) {
    $db          = getDB();
    $orderNumber = generateOrderNumber();
    $bd          = orderBreakdown($cart, $extra['discount'] ?? 0, $extra['delivery_charge'] ?? 0);
    $cPhone      = $extra['customer_phone'] ?? null;

    try { $db->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS customer_phone VARCHAR(20) DEFAULT NULL"); } catch(Exception $e){}
    try { $db->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS gst_amount DECIMAL(10,2) DEFAULT 0"); } catch(Exception $e){}

    $db->prepare("INSERT INTO orders (order_number, phone, customer_name, customer_phone, items, subtotal, discount_amount, delivery_charge, gst_amount, total, payment_method, coupon_code, delivery_address, customer_lat, customer_lng, distance_km) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$orderNumber, $phone, $name, $cPhone, json_encode($cart),
                  $bd['subtotal'], $bd['discount'], $bd['delivery'], $bd['gst'], $bd['total'],
                  $extra['payment_method'] ?? 'online', $extra['coupon_code'] ?? null,
                  $extra['address'] ?? null, $extra['lat'] ?? null, $extra['lng'] ?? null, $extra['distance'] ?? null]);

    return ['order_number' => $orderNumber, 'total' => $bd['total'], 'subtotal' => $bd['subtotal'],
            'gst' => $bd['gst'], 'discount' => $bd['discount'], 'delivery' => $bd['delivery'], 'id' => $db->lastInsertId()];
}

// ============================================
//  RAZORPAY
// ============================================
function createPaymentLink($orderId, $orderNumber, $amount, $phone, $name) {
    $keyId  = rzpKeyId();
    $secret = rzpSecret();
    if (!$keyId || !$secret) { error_log("Razorpay keys missing"); return null; }

    $data = [
        "amount"          => round($amount * 100),
        "currency"        => getSetting('currency', 'INR'),
        "accept_partial"  => false,
        "description"     => "Order #{$orderNumber} - " . getSetting('restaurant_name', 'Restaurant'),
        "customer"        => ["name" => $name ?: "Customer", "contact" => "+{$phone}"],
        "notify"          => ["sms" => false, "email" => false],
        "reminder_enable" => false,
        "notes"           => ["order_number" => $orderNumber, "order_id" => $orderId],
        "callback_url"    => baseUrl() . "/payment-callback.php",
        "callback_method" => "get"
    ];

    $ch = curl_init("https://api.razorpay.com/v1/payment_links");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_USERPWD        => "{$keyId}:{$secret}",
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json"]
    ]);
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (isset($response['short_url'])) {
        getDB()->prepare("UPDATE orders SET razorpay_order_id=?, payment_link=? WHERE id=?")
               ->execute([$response['id'], $response['short_url'], $orderId]);
        return $response['short_url'];
    }
    error_log("Razorpay error: " . json_encode($response));
    return null;
}

// ============================================
//  NOTIFICATIONS
// ============================================
function notifyRestaurant($order, $cart) {
    $pm  = ($order['payment_method'] ?? '') === 'cod' ? 'Cash on Delivery' : 'Online Paid';
    $msg = "*NAYA ORDER #{$order['order_number']}* | {$pm}\n\n";
    $msg .= "Customer: {$order['customer_name']}";
    if (!empty($order['customer_phone'])) $msg .= " | Ph: {$order['customer_phone']}";
    $msg .= "\nWA: +{$order['phone']}\n";
    $msg .= "Address: {$order['delivery_address']}\n\n";
    foreach ($cart as $item) {
        $msg .= "- {$item['name']} x{$item['qty']} = Rs." . ($item['price'] * $item['qty']) . "\n";
    }
    $msg .= "\nSubtotal: Rs.{$order['subtotal']}";
    if (!empty($order['gst_amount']) && $order['gst_amount'] > 0) $msg .= "\nGST: Rs.{$order['gst_amount']}";
    if ($order['delivery_charge'] > 0) $msg .= "\nDelivery: Rs.{$order['delivery_charge']}";
    if ($order['discount_amount'] > 0) $msg .= "\nDiscount: -Rs.{$order['discount_amount']}";
    $msg .= "\n*Total: Rs.{$order['total']}*";
    sendWhatsApp(restPhone(), $msg);
}

function sendReviewLink($phone, $name, $orderNumber) {
    $link = getSetting('google_review_link', '');
    if (!$link) return;
    $msg  = "Shukriya *{$name}* ji!\n\n";
    $msg .= "Order #{$orderNumber} - hope karte hain khaana pasand aaya hoga!\n\n";
    $msg .= "Google review dena ji:\n{$link}\n\n";
    $msg .= "Teri feedback saadi team liye bahut zaroori hai!";
    sendWhatsApp($phone, $msg);
}

// ============================================
//  LOG
// ============================================
function logMessage($phone, $direction, $message) {
    try {
        getDB()->prepare("INSERT INTO message_logs (phone, direction, message) VALUES (?,?,?)")
               ->execute([$phone, $direction, $message]);
    } catch (Exception $e) { error_log("Log err: " . $e->getMessage()); }
}

// ============================================
//  BILL GENERATION
// ============================================
function generateBillToken($orderId) {
    // 12 char short alphanumeric token — e.g. "aB3xK9mR2pQw"
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $token = '';
    for ($i = 0; $i < 12; $i++) {
        $token .= $chars[random_int(0, strlen($chars) - 1)];
    }
    try {
        getDB()->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS bill_token VARCHAR(64) DEFAULT NULL");
        getDB()->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS bill_viewed_at TIMESTAMP NULL DEFAULT NULL");
    } catch(Exception $e) {}
    getDB()->prepare("UPDATE orders SET bill_token=? WHERE id=?")->execute([$token, $orderId]);
    return $token;
}

function getBillUrl($token) {
    return baseUrl() . "/bill/view.php?t=" . $token;
}

function sendBillToCustomer($phone, $name, $orderId, $orderNumber) {
    $token   = generateBillToken($orderId);
    $billUrl = getBillUrl($token);
    $msg  = "Tera bill ready hai!\n\n";
    $msg .= "Order #{$orderNumber}\n\n";
    $msg .= "Bill dekhne/download karne liye:\n";
    $msg .= $billUrl . "\n\n";
    $msg .= "_Save as PDF laye Print button use karo_";
    sendWhatsApp($phone, $msg);
    return $token;
}

// ============================================
//  STORE HOURS — Open/Closed Check
// ============================================

function isStoreOpen() {
    // 1. Manual toggle
    if (getSetting('store_open', '1') !== '1') {
        return ['open' => false, 'reason' => 'manual'];
    }

    // 2. Schedule check using store_schedule table
    $db = getDB();
    try {
        // Ensure table exists
        $db->exec("CREATE TABLE IF NOT EXISTS store_schedule (
            id INT AUTO_INCREMENT PRIMARY KEY,
            day_of_week TINYINT NOT NULL UNIQUE,
            day_name VARCHAR(15) NOT NULL,
            is_open TINYINT(1) DEFAULT 1,
            open_time TIME DEFAULT '10:00:00',
            close_time TIME DEFAULT '22:00:00'
        )");
        // Seed if empty
        $count = $db->query("SELECT COUNT(*) FROM store_schedule")->fetchColumn();
        if ($count == 0) {
            $days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
            foreach ($days as $i => $d) {
                $db->prepare("INSERT IGNORE INTO store_schedule (day_of_week, day_name) VALUES (?,?)")->execute([$i,$d]);
            }
        }
    } catch(Exception $e) { return ['open' => true]; }

    $dayNum  = (int)date('w'); // 0=Sun
    $nowTime = date('H:i:s');

    $stmt = $db->prepare("SELECT * FROM store_schedule WHERE day_of_week = ?");
    $stmt->execute([$dayNum]);
    $row = $stmt->fetch();

    if (!$row || !$row['is_open']) {
        return ['open' => false, 'reason' => 'day_off', 'day' => date('l')];
    }
    if ($nowTime < $row['open_time']) {
        return ['open' => false, 'reason' => 'not_yet',
                'open_time' => date('h:i A', strtotime($row['open_time']))];
    }
    if ($nowTime > $row['close_time']) {
        return ['open' => false, 'reason' => 'closed',
                'close_time' => date('h:i A', strtotime($row['close_time']))];
    }
    return ['open' => true, 'open_time' => date('h:i A', strtotime($row['open_time'])),
            'close_time' => date('h:i A', strtotime($row['close_time']))];
}

function getStoreTimingsText() {
    $db    = getDB();
    $rows  = $db->query("SELECT * FROM store_schedule ORDER BY day_of_week")->fetchAll();
    $lines = [];
    foreach ($rows as $r) {
        if ($r['is_open']) {
            $open  = date('h:i A', strtotime($r['open_time']));
            $close = date('h:i A', strtotime($r['close_time']));
            $lines[] = "*{$r['day_name']}:* {$open} - {$close}";
        } else {
            $lines[] = "*{$r['day_name']}:* Band";
        }
    }
    return implode("\n", $lines);
}

function getClosedMessage($status) {
    $restName = getSetting('restaurant_name', 'Restaurant');
    $timings  = getStoreTimingsText();
    $template = getSetting('store_closed_msg',
        "Sorry ji, abhi {restaurant} band hai.\n\nHumari timings:\n{timings}\n\nBaad mein try karna ji!");

    $msg = str_replace('{restaurant}', $restName, $template);
    $msg = str_replace('{timings}',    $timings,  $msg);
    $msg = str_replace('\n', "\n", $msg);

    // Add specific reason
    switch ($status['reason']) {
        case 'manual':
            $msg = "Sorry ji, abhi *{$restName}* temporarily band hai.\n\nJald hi khulega — thodi der baad try karna ji!";
            break;
        case 'not_yet':
            $msg = "Sorry ji, *{$restName}* abhi band hai.\n\nAaj khulega: *{$status['open_time']}*\n\nTimings:\n{$timings}";
            break;
        case 'closed':
            $msg = "Sorry ji, aaj ka time ho gaya. *{$restName}* band ho chuka hai.\n\nKal milte hain!\n\nTimings:\n{$timings}";
            break;
        case 'day_off':
            $msg = "Sorry ji, aaj *{$status['day']}* ko *{$restName}* band hai.\n\nTimings:\n{$timings}";
            break;
    }
    return $msg;
}

// ============================================
//  STORE HOURS CHECK
// ============================================


function getStoreClosedMessage($status) {
    $base = getSetting('store_closed_message', '');
    $reason = $status['reason'] ?? '';
    
    if ($base) {
        // Append timing info to custom message
        if ($reason === 'not_yet' && !empty($status['open_time'])) {
            return $base . "

Aaj khulega: *" . $status['open_time'] . "*";
        }
        if ($reason === 'closed' && !empty($status['close_time'])) {
            return $base . "

(Band hua: " . $status['close_time'] . ")";
        }
        return $base;
    }

    return match($reason) {
        'manual'   => "Maafi karo ji, abhi restaurant band hai.
Jald hi khulega — dobara try karo ji!",
        'day_off'  => "Aaj " . ($status['day'] ?? 'is din') . " restaurant band hai ji.
Kal zaroor aao!",
        'not_yet'  => "Restaurant abhi nahi khula ji.
Khulne ka time: *" . ($status['open_time'] ?? '') . "*
Thoda intezaar karo!",
        'closed'   => "Restaurant aaj band ho gaya ji.
Kal dobara aao! Shukriya",
        default    => "Maafi karo ji, abhi restaurant band hai."
    };
}

