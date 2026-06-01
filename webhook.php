<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/helpers.php';

// Webhook verification
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';
    if ($mode === 'subscribe' && $token === verifyTok()) {
        http_response_code(200); echo $challenge;
    } else {
        http_response_code(403); echo "Forbidden";
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { exit; }

$input  = file_get_contents('php://input');
$data   = json_decode($input, true);
error_log("WA IN: " . $input);

$msgObj = $data['entry'][0]['changes'][0]['value']['messages'][0] ?? null;
if (!$msgObj) { http_response_code(200); echo "ok"; exit; }

$phone   = $msgObj['from'];
$type    = $msgObj['type'];
$msgText = '';
$msgRaw  = '';
$replyId = '';

if ($type === 'text') {
    $msgRaw  = trim($msgObj['text']['body'] ?? '');
    $msgText = strtolower($msgRaw);
} elseif ($type === 'interactive') {
    $iType   = $msgObj['interactive']['type'] ?? '';
    $replyId = ($iType === 'list_reply')
        ? ($msgObj['interactive']['list_reply']['id']    ?? '')
        : ($msgObj['interactive']['button_reply']['id'] ?? '');
    $msgText = $replyId;
} elseif ($type === 'location') {
    // Customer ne WhatsApp location share kiti
    $lat  = $msgObj['location']['latitude']  ?? null;
    $lng  = $msgObj['location']['longitude'] ?? null;
    $name = $msgObj['location']['name']      ?? null;
    $addr = $msgObj['location']['address']   ?? null;

    $sess = getSession($phone);
    if ($sess['state'] === 'GET_ADDRESS' && $lat && $lng) {
        // Location mile — delivery area check karo
        $restLat = (float)getSetting('restaurant_lat', 0);
        $restLng = (float)getSetting('restaurant_lng', 0);
        $maxKm   = (float)getSetting('service_radius_km', 5);
        $dist    = 0;
        $areaOk  = true;

        if ($restLat && $restLng) {
            $dist    = getDistanceKm($restLat, $restLng, $lat, $lng);
            $areaOk  = ($dist <= $maxKm);
        }

        if (!$areaOk) {
            sendWhatsApp($phone, "Sorry ji, teri location saadi service area ({$maxKm} km) de bahar hai.
Distance: " . round($dist,1) . " km

*pickup* type karo agar khud lene aaoge.");
            http_response_code(200); echo "ok"; exit;
        }

        // Build address string from location
        $locationAddr = $name ?: ($addr ?: "Location: {$lat},{$lng}");
        if ($addr && $name) $locationAddr = $name . ", " . $addr;

        // Save to session
        try { getDB()->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS temp_address TEXT DEFAULT NULL"); } catch(Exception $e){}
        try { getDB()->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS temp_lat DECIMAL(10,8) DEFAULT NULL"); } catch(Exception $e){}
        try { getDB()->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS temp_lng DECIMAL(11,8) DEFAULT NULL"); } catch(Exception $e){}
        try { getDB()->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS temp_dist DECIMAL(5,2) DEFAULT NULL"); } catch(Exception $e){}
        getDB()->prepare("UPDATE sessions SET temp_address=?, temp_lat=?, temp_lng=?, temp_dist=? WHERE phone=?")
               ->execute([$locationAddr, $lat, $lng, round($dist,1), $phone]);

        $del = calculateDeliveryCharge(cartTotal(getCart($phone)));
        updateSession($phone, ['state' => 'CHOOSE_PAYMENT', 'delivery_charge' => $del]);

        $distMsg = ($dist > 0) ? "
Distance: " . round($dist,1) . " km" : "";

        $sess2    = getSession($phone);
        $cart     = getCart($phone);
        $discount = (float)($sess2['pending_discount'] ?? 0);
        $delivery = (float)($sess2['delivery_charge']  ?? 0);

        sendWhatsApp($phone, "Location mil gayi!
Address: {$locationAddr}{$distMsg}

" . cartSummary($cart, $discount, $delivery));

        $codEnabled    = getSetting('cod_enabled', '1') === '1';
        $onlineEnabled = getSetting('online_payment_enabled', '1') === '1';
        $buttons = [];
        if ($onlineEnabled) $buttons['pay_online'] = 'Online Pay';
        if ($codEnabled)    $buttons['pay_cod']    = 'Cash on Delivery';
        sendButtonMessage($phone, "Payment method choose karo:", $buttons, "Payment", "");
    } else {
        sendWhatsApp($phone, "Location mil gayi! Lekin pehle order start karo.
*menu* type karo.");
    }
    http_response_code(200); echo "ok"; exit;
} else {
    sendWhatsApp($phone, "Sorry ji, sirf text/buttons samajh sakda haun.\n*Hi* type karo shuru karne liye.");
    http_response_code(200); echo "ok"; exit;
}

logMessage($phone, 'in', $msgText);
$session = getSession($phone);
$state   = $session['state'];

// ============================================
//  STORE OPEN/CLOSED CHECK
//  Exception: allow "menu", "hi", "timings" always
//  So customer can see timings even when closed
// ============================================
$alwaysAllow = ['hi','hello','hii','menu','timings','time','kado khulda','help'];
$isInfoOnly  = in_array($msgText, $alwaysAllow);

if (!$isInfoOnly) {
    $storeStatus = isStoreOpen();
    if (!$storeStatus['open']) {
        sendWhatsApp($phone, getClosedMessage($storeStatus));
        http_response_code(200); echo "ok"; exit;
    }
}

// ============================================
//  INTERACTIVE BUTTON HANDLERS
// ============================================

// Category selected from list
if (str_starts_with($replyId, 'cat_')) {
    $catId = (int)str_replace('cat_', '', $replyId);
    updateSession($phone, ['state' => 'ITEM_SELECT', 'selected_category' => $catId]);
    sendItemsMenu($phone, $catId);
    http_response_code(200); echo "ok"; exit;
}

// Item selected from list — check addons first, then ask quantity
if (str_starts_with($replyId, 'item_')) {
    $itemId = (int)str_replace('item_', '', $replyId);
    $stmt   = getDB()->prepare("SELECT * FROM menu_items WHERE id=? AND is_available=1");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    if ($item) {
        // Ensure addon table + session column exist
        try { getDB()->exec("CREATE TABLE IF NOT EXISTS item_addons (id INT AUTO_INCREMENT PRIMARY KEY, item_id INT NOT NULL, name VARCHAR(100) NOT NULL, price DECIMAL(10,2) DEFAULT 0, is_active TINYINT(1) DEFAULT 1, sort_order INT DEFAULT 0)"); } catch(Exception $e) {}
        try { getDB()->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS pending_addons TEXT DEFAULT NULL"); } catch(Exception $e) {}

        updateSession($phone, ['pending_item_id' => $itemId]);
        // Reset pending_addons safely
        try { getDB()->prepare("UPDATE sessions SET pending_addons=NULL WHERE phone=?")->execute([$phone]); } catch(Exception $e) {}

        // Check if this item has add-ons
        try {
            $adStmt = getDB()->prepare("SELECT * FROM item_addons WHERE item_id=? AND is_active=1 ORDER BY sort_order, id");
            $adStmt->execute([$itemId]);
            $addons = $adStmt->fetchAll();
        } catch(Exception $e) { $addons = []; }

        if (!empty($addons)) {
            // Show addon selection
            $msg = "*{$item['name']}* — Rs.{$item['price']}\n\n";
            $msg .= "➕ *Add-ons available hain:*\n";
            $i = 1;
            foreach ($addons as $a) {
                $priceStr = $a['price'] > 0 ? " (+Rs.{$a['price']})" : " (Free)";
                $msg .= "{$i}. {$a['name']}{$priceStr}\n";
                $i++;
            }
            $msg .= "\n*Number(s) type karo* jido add-on chahide:\n";
            $msg .= "Example: _1 3_ (1st aur 3rd)\n";
            $msg .= "Ya _skip_ type karo koi add-on nahi chahida";
            updateSession($phone, ['state' => 'SELECT_ADDON']);
            sendWhatsApp($phone, $msg);
        } else {
            // No addons — directly ask qty
            updateSession($phone, ['state' => 'SELECT_QTY']);
            sendButtonMessage(
                $phone,
                "*{$item['name']}*\nRs.{$item['price']} per plate\n\nKitni qty chahidi?\n(Ya seedha type karo: 1, 2, 3...)",
                ['qty_1' => '1 Plate', 'qty_2' => '2 Plates', 'qty_3' => '3 Plates'],
                "Quantity Choose Karo",
                "Zyada leni? Type karo e.g. 5"
            );
        }
    } else {
        sendWhatsApp($phone, "Item nahi mila. Dobara try karo.");
        sendCategoryMenu($phone);
    }
    http_response_code(200); echo "ok"; exit;
}

// Qty button tapped (qty_1, qty_2, qty_3)
if (str_starts_with($replyId, 'qty_')) {
    $qty    = (int)str_replace('qty_', '', $replyId);
    $itemId = (int)($session['pending_item_id'] ?? 0);
    if ($itemId) {
        $stmt = getDB()->prepare("SELECT * FROM menu_items WHERE id=? AND is_available=1");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        if ($item) {
            $addons = [];
            try { $addons = json_decode($session['pending_addons'] ?? 'null', true) ?: []; } catch(Exception $e) {}
            $tmpCart = null;
            addToCart($phone, $item, $qty, $tmpCart, $addons);
            $cart = getCart($phone);
            $del  = calculateDeliveryCharge(cartTotal($cart));
            updateSession($phone, ['state' => 'ADD_MORE', 'delivery_charge' => $del]);
            try { getDB()->prepare("UPDATE sessions SET pending_addons=NULL WHERE phone=?")->execute([$phone]); } catch(Exception $e) {}
            $addonNames = !empty($addons) ? "\n   ➕ " . implode(', ', array_column($addons, 'name')) : '';
            sendWhatsApp($phone, "✅ Added: *{$item['name']}* x{$qty}{$addonNames}\n\n" . cartSummary($cart, 0, $del));
            sendButtonMessage($phone, "Aur add karo ya order confirm karo?",
                ['btn_confirm' => 'Confirm Order', 'btn_addmore' => 'Aur Add Karo', 'btn_editcart' => 'Edit Cart']);
        }
    }
    http_response_code(200); echo "ok"; exit;
}

// ============================================
//  btn_confirm — FIXED: handle directly, no fallthrough
// ============================================
if ($replyId === 'btn_confirm') {
    $cart     = getCart($phone);
    $sess2    = getSession($phone);
    $minOrder = (float)getSetting('min_order_amount', 100);

    if (empty($cart)) {
        sendWhatsApp($phone, "Cart khali hai! Pehle kuch add karo.");
        sendCategoryMenu($phone);
        http_response_code(200); echo "ok"; exit;
    }
    if (cartTotal($cart) < $minOrder) {
        sendWhatsApp($phone, "Minimum order Rs.{$minOrder} da hai.\nTera total Rs." . cartTotal($cart) . " hai. Kuch aur add karo.");
        sendButtonMessage($phone, "Kya karna hai?",
            ['btn_addmore' => 'Aur Add Karo', 'btn_editcart' => 'Edit Cart']);
        http_response_code(200); echo "ok"; exit;
    }
    if (empty($sess2['customer_name'])) {
        updateSession($phone, ['state' => 'GET_NAME']);
        sendWhatsApp($phone, "Order confirm karne liye apna *naam* type karo ji");
    } else {
        updateSession($phone, ['state' => 'ASK_COUPON']);
        sendButtonMessage($phone, "Koi coupon code hai?",
            ['btn_hascoupon' => 'Coupon Lagao', 'btn_nocoupon' => 'Skip Karo'],
            "Discount Coupon", "");
    }
    http_response_code(200); echo "ok"; exit;
}

// btn_addmore
if ($replyId === 'btn_addmore') {
    updateSession($phone, ['state' => 'CATEGORY_SELECT']);
    sendCategoryMenu($phone);
    http_response_code(200); echo "ok"; exit;
}

// btn_editcart
if ($replyId === 'btn_editcart') {
    updateSession($phone, ['state' => 'CART_EDIT']);
    sendWhatsApp($phone, buildEditCartMenu(getCart($phone)));
    http_response_code(200); echo "ok"; exit;
}

// Coupon buttons
if ($replyId === 'btn_hascoupon') {
    updateSession($phone, ['state' => 'APPLY_COUPON']);
    sendWhatsApp($phone, "Apna coupon code type karo:");
    http_response_code(200); echo "ok"; exit;
}
if ($replyId === 'btn_nocoupon') {
    updateSession($phone, ['state' => 'GET_ADDRESS', 'pending_coupon' => null, 'pending_discount' => 0]);
    sendButtonMessage($phone,
            "Delivery kidan karni hai?",
            ['addr_type' => 'Address Type Karo', 'addr_loc' => 'Location Share Karo', 'addr_pickup' => 'Self Pickup'],
            "Delivery Option", "Location share = auto area check"
        );
    http_response_code(200); echo "ok"; exit;
}

// Address delivery type buttons
if ($replyId === 'addr_type') {
    updateSession($phone, ['state' => 'GET_ADDRESS']);
    sendWhatsApp($phone, "Apna full delivery address type karo ji
(Ghar no., gali, shehar, pincode)");
    http_response_code(200); echo "ok"; exit;
}
if ($replyId === 'addr_loc') {
    updateSession($phone, ['state' => 'GET_ADDRESS']);
    $locMsg  = "Apni WhatsApp *current location* share karo:

";
    $locMsg .= "1. Neeche *Attach* (+) button tap karo
";
    $locMsg .= "2. *Location* choose karo
";
    $locMsg .= "3. *Send your current location* tap karo

";
    $locMsg .= "Delivery area turant check ho jaayega!";
    sendWhatsApp($phone, $locMsg);
    http_response_code(200); echo "ok"; exit;
}
if ($replyId === 'addr_pickup') {
    try { getDB()->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS temp_address TEXT DEFAULT NULL"); } catch(Exception $e){}
    getDB()->prepare("UPDATE sessions SET temp_address='PICKUP' WHERE phone=?")->execute([$phone]);
    updateSession($phone, ['state' => 'CHOOSE_PAYMENT', 'delivery_charge' => 0]);
    $cart_p   = getCart($phone); $sess_p = getSession($phone);
    $discount_p = (float)($sess_p['pending_discount'] ?? 0);
    $codEnabled    = getSetting('cod_enabled', '1') === '1';
    $onlineEnabled = getSetting('online_payment_enabled', '1') === '1';
    $btns_p = [];
    if ($onlineEnabled) $btns_p['pay_online'] = 'Online Pay';
    if ($codEnabled)    $btns_p['pay_cod']    = 'Cash on Delivery';
    sendWhatsApp($phone, cartSummary($cart_p, $discount_p, 0));
    sendButtonMessage($phone, "Payment method choose karo:", $btns_p, "Payment", "Self Pickup — delivery free");
    http_response_code(200); echo "ok"; exit;
}

// Payment method buttons — with duplicate order lock
if ($replyId === 'pay_online' || $replyId === 'pay_cod') {
    $sess3 = getSession($phone);
    if (in_array($sess3['state'], ['AWAITING_PAYMENT', 'ORDER_PLACED'])) {
        sendWhatsApp($phone, "Tera order already place ho chuka hai.\nNaya order: *menu* type karo.");
        http_response_code(200); echo "ok"; exit;
    }
    // Lock state immediately to prevent double-tap
    updateSession($phone, [
        'state'          => 'ORDER_PLACED',
        'payment_method' => ($replyId === 'pay_cod') ? 'cod' : 'online'
    ]);
    placeOrder($phone);
    http_response_code(200); echo "ok"; exit;
}

// ============================================
//  GLOBAL TEXT COMMANDS
// ============================================

// Store open check for ALL order-related button taps
$storeStatus = isStoreOpen();
$orderButtons = ['btn_confirm','btn_addmore','btn_editcart','btn_hascoupon','btn_nocoupon','pay_online','pay_cod'];
if (!$storeStatus['open'] && !empty($replyId) && in_array($replyId, $orderButtons)) {
    sendWhatsApp($phone, getStoreClosedMessage($storeStatus));
    http_response_code(200); echo "ok"; exit;
}

if (in_array($msgText, ['menu', 'start', 'hi', 'hello', 'hii', 'sat sri akal', 'order'])) {
    $storeCheck = isStoreOpen();
    if (!$storeCheck['open']) {
        sendWhatsApp($phone, getStoreClosedMessage($storeCheck));
    } else {
        updateSession($phone, ['state' => 'CATEGORY_SELECT', 'cart' => '[]', 'pending_coupon' => null, 'pending_discount' => 0, 'delivery_charge' => 0]);
        sendCategoryMenu($phone);
    }
    http_response_code(200); echo "ok"; exit;
}

// Timings command — always works even when closed
if (in_array($msgText, ['timings', 'time', 'kado khulda', 'schedule', 'hours'])) {
    $storeCheck = isStoreOpen();
    $tz   = getSetting('store_timezone', 'Asia/Kolkata');
    $now  = new DateTime('now', new DateTimeZone($tz));
    $timeStr = $now->format('h:i A');
    $status  = $storeCheck['open'] ? "Khula hai" : "Band hai";
    $statusEmoji = $storeCheck['open'] ? "🟢" : "🔴";
    $msg  = "{$statusEmoji} *Abhi: {$status}* ({$timeStr})

";
    $msg .= "*Saari timings:*
";
    $msg .= getStoreTimingsText();
    sendWhatsApp($phone, $msg);
    http_response_code(200); echo "ok"; exit;
}

if ($msgText === 'cart') {
    sendCartWithButtons($phone);
    http_response_code(200); echo "ok"; exit;
}

if ($msgText === 'clear') {
    saveCart($phone, []);
    updateSession($phone, ['state' => 'CATEGORY_SELECT', 'pending_coupon' => null, 'pending_discount' => 0, 'delivery_charge' => 0]);
    sendWhatsApp($phone, "Cart saaf ho gaya");
    sendCategoryMenu($phone);
    http_response_code(200); echo "ok"; exit;
}

// confirm text command
if ($msgText === 'confirm') {
    $cart     = getCart($phone);
    $sess4    = getSession($phone);
    $minOrder = (float)getSetting('min_order_amount', 100);
    if (empty($cart)) {
        sendWhatsApp($phone, "Cart khali hai! Pehle kuch add karo.");
        sendCategoryMenu($phone);
        http_response_code(200); echo "ok"; exit;
    }
    if (cartTotal($cart) < $minOrder) {
        sendWhatsApp($phone, "Minimum order Rs.{$minOrder} da hai. Tera total Rs." . cartTotal($cart) . " hai.");
        http_response_code(200); echo "ok"; exit;
    }
    if (empty($sess4['customer_name'])) {
        updateSession($phone, ['state' => 'GET_NAME']);
        sendWhatsApp($phone, "Apna *naam* type karo ji");
    } else {
        updateSession($phone, ['state' => 'ASK_COUPON']);
        sendButtonMessage($phone, "Koi coupon code hai?",
            ['btn_hascoupon' => 'Coupon Lagao', 'btn_nocoupon' => 'Skip Karo'],
            "Discount Coupon", "");
    }
    http_response_code(200); echo "ok"; exit;
}

if ($msgText === 'help') {
    sendWhatsApp($phone, "Commands:\n- menu\n- cart\n- confirm\n- clear\n- remove 1\n- qty 1 3\n\nCall: +" . getSetting('restaurant_phone', restPhone()));
    http_response_code(200); echo "ok"; exit;
}

// remove item
if (preg_match('/^remove\s+(\d+)$/i', $msgText, $m)) {
    $cart = getCart($phone);
    $idx  = (int)$m[1] - 1;
    if (isset($cart[$idx])) {
        $n = $cart[$idx]['name'];
        array_splice($cart, $idx, 1);
        $cart = array_values($cart);
        saveCart($phone, $cart);
        $del = calculateDeliveryCharge(cartTotal($cart));
        updateSession($phone, ['delivery_charge' => $del]);
        if (empty($cart)) { sendWhatsApp($phone, "{$n} hataya. Cart khali."); sendCategoryMenu($phone); }
        else sendWhatsApp($phone, "{$n} hataya.\n\n" . cartSummary($cart, 0, $del));
    } else {
        sendWhatsApp($phone, "Galat number.");
    }
    http_response_code(200); echo "ok"; exit;
}

// qty update
if (preg_match('/^qty\s+(\d+)\s+(\d+)$/i', $msgText, $m)) {
    $cart = getCart($phone);
    $idx  = (int)$m[1] - 1;
    $nq   = min(10, max(1, (int)$m[2]));
    if (isset($cart[$idx])) {
        $cart[$idx]['qty'] = $nq;
        saveCart($phone, $cart);
        $del = calculateDeliveryCharge(cartTotal($cart));
        updateSession($phone, ['delivery_charge' => $del]);
        sendWhatsApp($phone, "{$cart[$idx]['name']} qty updated to {$nq}.\n\n" . cartSummary($cart, 0, $del));
    } else {
        sendWhatsApp($phone, "Galat number.");
    }
    http_response_code(200); echo "ok"; exit;
}

// ============================================
//  STATE MACHINE
// ============================================
switch ($state) {

    case 'WELCOME':
    case 'CATEGORY_SELECT':
        sendCategoryMenu($phone);
        break;

    // SELECT_ADDON — customer types addon numbers or "skip"
    case 'SELECT_ADDON':
        $itemId = (int)($session['pending_item_id'] ?? 0);
        $input  = strtolower(trim($msgRaw));

        if ($input === 'skip' || $input === '0') {
            // No addons selected
            updateSession($phone, ['state' => 'SELECT_QTY']);
            try { getDB()->prepare("UPDATE sessions SET pending_addons=NULL WHERE phone=?")->execute([$phone]); } catch(Exception $e) {}
        } else {
            // Parse typed numbers e.g. "1 3" or "1,3" or "1 2 3"
            $nums = preg_split('/[\s,]+/', $input);
            $nums = array_filter(array_map('intval', $nums), fn($n) => $n > 0);

            if (empty($nums)) {
                sendWhatsApp($phone, "Add-on numbers type karo (e.g. _1 2_) ya _skip_ type karo.");
                break;
            }

            // Fetch selected addons from DB
            try {
                $adStmt = getDB()->prepare("SELECT * FROM item_addons WHERE item_id=? AND is_active=1 ORDER BY sort_order, id");
                $adStmt->execute([$itemId]);
                $allAddons = $adStmt->fetchAll();
            } catch(Exception $e) { $allAddons = []; }

            $selected = [];
            foreach ($nums as $n) {
                $idx = $n - 1;
                if (isset($allAddons[$idx])) {
                    $selected[] = [
                        'id'    => $allAddons[$idx]['id'],
                        'name'  => $allAddons[$idx]['name'],
                        'price' => (float)$allAddons[$idx]['price'],
                    ];
                }
            }

            if (empty($selected)) {
                sendWhatsApp($phone, "Galat number dita. Sahi number type karo ya _skip_ likho.");
                break;
            }

            updateSession($phone, ['state' => 'SELECT_QTY']);
            try { getDB()->prepare("UPDATE sessions SET pending_addons=? WHERE phone=?")->execute([json_encode($selected), $phone]); } catch(Exception $e) {}

            $addonText = implode(', ', array_map(fn($a) => $a['name'] . ($a['price'] > 0 ? " +Rs.{$a['price']}" : ''), $selected));
            sendWhatsApp($phone, "✅ Add-ons: *{$addonText}*\n\nHune qty dasao:");
        }

        // Show qty buttons
        $stmt2 = getDB()->prepare("SELECT * FROM menu_items WHERE id=? AND is_available=1");
        $stmt2->execute([$itemId]);
        $item2 = $stmt2->fetch();
        if ($item2) {
            sendButtonMessage($phone,
                "*{$item2['name']}*\nRs.{$item2['price']}/plate\n\nKitni qty chahidi?",
                ['qty_1' => '1 Plate', 'qty_2' => '2 Plates', 'qty_3' => '3 Plates'],
                "Quantity", "Zyada? Type karo e.g. 5"
            );
        }
        break;

    // SELECT_QTY — customer typed number manually
    case 'SELECT_QTY':
        if (is_numeric($msgText) && (int)$msgText > 0) {
            $qty    = min(20, (int)$msgText);
            $itemId = (int)($session['pending_item_id'] ?? 0);
            if ($itemId) {
                $stmt = getDB()->prepare("SELECT * FROM menu_items WHERE id=? AND is_available=1");
                $stmt->execute([$itemId]);
                $item = $stmt->fetch();
                if ($item) {
                    $addons = [];
                    try { $addons = json_decode($session['pending_addons'] ?? 'null', true) ?: []; } catch(Exception $e) {}
                    $tmpCart = null;
                    addToCart($phone, $item, $qty, $tmpCart, $addons);
                    $cart = getCart($phone);
                    $del  = calculateDeliveryCharge(cartTotal($cart));
                    updateSession($phone, ['state' => 'ADD_MORE', 'delivery_charge' => $del]);
                    try { getDB()->prepare("UPDATE sessions SET pending_addons=NULL WHERE phone=?")->execute([$phone]); } catch(Exception $e) {}
                    $addonNames = !empty($addons) ? "\n   ➕ " . implode(', ', array_column($addons, 'name')) : '';
                    sendWhatsApp($phone, "✅ Added: *{$item['name']}* x{$qty}{$addonNames}\n\n" . cartSummary($cart, 0, $del));
                    sendButtonMessage($phone, "Aur add karo ya confirm?",
                        ['btn_confirm' => 'Confirm Order', 'btn_addmore' => 'Aur Add Karo', 'btn_editcart' => 'Edit Cart']);
                }
            }
        } else {
            sendWhatsApp($phone, "Quantity type karo (1-20) ya upar wale buttons use karo.");
        }
        break;

    // ITEM_SELECT — ecommerce format "5:2 9:1 12:3"
    case 'ITEM_SELECT':
        $catId  = (int)$session['selected_category'];
        $msgOri = trim($msgObj['text']['body'] ?? $msgRaw);

        // Parse ecommerce format: "5:2 9:1" or "5:2" or legacy "5 2"
        $parsed = parseOrderFormat($msgOri);

        // Fallback: legacy format "itemId qty" or just "itemId"
        if (empty($parsed)) {
            $parts = preg_split('/\s+/', trim($msgText));
            if (count($parts) >= 1 && ctype_digit($parts[0])) {
                $iId = (int)$parts[0];
                $qty = isset($parts[1]) && ctype_digit($parts[1]) ? (int)$parts[1] : 1;
                $parsed = [['id' => $iId, 'qty' => $qty]];
            }
        }

        if (empty($parsed)) {
            sendWhatsApp($phone, "Format sahi nahi. Example: 5:2 9:1\n(item number : quantity)");
            sendItemsMenu($phone, $catId);
            break;
        }

        $cart  = getCart($phone);
        $added = [];
        $db    = getDB();

        foreach ($parsed as $p) {
            $stmt = $db->prepare("SELECT * FROM menu_items WHERE id=? AND category_id=? AND is_available=1");
            $stmt->execute([$p['id'], $catId]);
            $item = $stmt->fetch();
            if ($item) {
                addToCart($phone, $item, $p['qty'], $cart);
                $added[] = "- {$item['name']} x{$p['qty']} = Rs." . ($item['price'] * $p['qty']);
            } else {
                $added[] = "- Item #{$p['id']} nahi mila (skip)";
            }
        }

        saveCart($phone, $cart);
        $del = calculateDeliveryCharge(cartTotal($cart));
        updateSession($phone, ['state' => 'ADD_MORE', 'delivery_charge' => $del]);

        $addedCount = count(array_filter($parsed, fn($p) => true));
        $msg = count($added) . " item(s) add hoe:\n" . implode("\n", $added);
        $msg .= "\n\n" . cartSummary($cart, 0, $del);
        sendWhatsApp($phone, $msg);
        sendButtonMessage($phone, "Aur add karo ya confirm?",
            ['btn_confirm' => 'Confirm Order', 'btn_addmore' => 'Aur Add Karo', 'btn_editcart' => 'Edit Cart']);
        break;

    // ADD_MORE — aur category select karo
    case 'ADD_MORE':
        $cats   = getCategories();
        $catIds = array_column($cats, 'id');
        if (in_array((int)$msgText, $catIds)) {
            updateSession($phone, ['state' => 'ITEM_SELECT', 'selected_category' => (int)$msgText]);
            sendItemsMenu($phone, (int)$msgText);
        } else {
            // Kuch bhi type kiya — show buttons again
            sendButtonMessage($phone, "Kya karna hai?",
                ['btn_confirm' => 'Confirm Order', 'btn_addmore' => 'Aur Add Karo', 'btn_editcart' => 'Edit Cart']);
        }
        break;

    // CART_EDIT
    case 'CART_EDIT':
        $cart = getCart($phone);
        if (preg_match('/^remove\s+(\d+)$/i', $msgText, $m)) {
            $idx = (int)$m[1] - 1;
            if (isset($cart[$idx])) {
                $n = $cart[$idx]['name'];
                array_splice($cart, $idx, 1);
                $cart = array_values($cart);
                saveCart($phone, $cart);
                if (empty($cart)) {
                    updateSession($phone, ['state' => 'CATEGORY_SELECT']);
                    sendWhatsApp($phone, "{$n} hataya. Cart khali.");
                    sendCategoryMenu($phone);
                } else {
                    sendWhatsApp($phone, "{$n} hataya.\n\n" . buildEditCartMenu($cart));
                }
            } else {
                sendWhatsApp($phone, "Galat number.\n\n" . buildEditCartMenu($cart));
            }
        } elseif (preg_match('/^qty\s+(\d+)\s+(\d+)$/i', $msgText, $m)) {
            $idx = (int)$m[1] - 1;
            $nq  = min(10, max(1, (int)$m[2]));
            if (isset($cart[$idx])) {
                $cart[$idx]['qty'] = $nq;
                saveCart($phone, $cart);
                sendWhatsApp($phone, "Qty updated.\n\n" . buildEditCartMenu($cart));
            } else {
                sendWhatsApp($phone, "Galat number.\n\n" . buildEditCartMenu($cart));
            }
        } elseif ($msgText === 'done') {
            updateSession($phone, ['state' => 'ADD_MORE']);
            sendCartWithButtons($phone);
        } else {
            sendWhatsApp($phone, buildEditCartMenu($cart));
        }
        break;

    // GET_NAME
    case 'GET_NAME':
        $name = ucwords(strtolower(trim($msgRaw)));
        if (strlen($name) < 2) { sendWhatsApp($phone, "Sahi naam type karo ji."); break; }
        updateSession($phone, ['customer_name' => $name, 'state' => 'GET_PHONE']);
        sendWhatsApp($phone, "Hello *{$name}* ji!\n\nApna *phone number* type karo (10 digits)");
        break;

    // GET_PHONE
    case 'GET_PHONE':
        $cPhone = preg_replace('/[^0-9]/', '', $msgRaw);
        // Remove country code prefixes
        if (strlen($cPhone) === 12 && substr($cPhone,0,2) === '91') $cPhone = substr($cPhone,2);
        if (strlen($cPhone) === 11 && substr($cPhone,0,1) === '0')  $cPhone = substr($cPhone,1);
        if (strlen($cPhone) !== 10) {
            $got = strlen($cPhone);
            sendWhatsApp($phone, "Valid 10 digit phone number type karo ji.\n"
                . "Example: 9876543210\n\n"
                . "Tusi {$got} digits dite — bilkul 10 chahide hain.");
            break;
        }
        updateSession($phone, ['customer_phone' => $cPhone, 'state' => 'ASK_COUPON']);
        sendButtonMessage($phone, "Koi coupon code hai? 🏷",
            ['btn_hascoupon' => 'Coupon Lagao', 'btn_nocoupon' => 'Skip Karo'],
            "Discount Coupon", "");
        break;

    // ASK_COUPON
    case 'ASK_COUPON':
        sendButtonMessage($phone, "Coupon code hai?",
            ['btn_hascoupon' => 'Coupon Lagao', 'btn_nocoupon' => 'Skip Karo'], "Discount", "");
        break;

    // APPLY_COUPON
    case 'APPLY_COUPON':
        $code   = strtoupper(trim($msgRaw));
        $cart   = getCart($phone);
        $result = validateCoupon($code, $phone, cartTotal($cart));
        if ($result['valid']) {
            $del = calculateDeliveryCharge(cartTotal($cart));
            updateSession($phone, [
                'pending_coupon'   => $code,
                'pending_discount' => $result['discount'],
                'delivery_charge'  => $del,
                'state'            => 'GET_ADDRESS'
            ]);
            sendWhatsApp($phone, $result['msg'] . "\n\n" . cartSummary($cart, $result['discount'], $del));
            sendWhatsApp($phone, "Apna *delivery address* type karo\n(Ya *pickup* type karo)");
        } else {
            sendWhatsApp($phone, $result['msg']);
            sendButtonMessage($phone, "Kya karna hai?",
                ['btn_hascoupon' => 'Dobara Try', 'btn_nocoupon' => 'Skip'], "Coupon", "");
        }
        break;

    // GET_ADDRESS
    case 'GET_ADDRESS':
        $address  = trim($msgRaw);
        $isPickup = strtolower($address) === 'pickup';
        // Agar koi "location" type kare ta remind karo
        if (strtolower($address) === 'location') {
            sendWhatsApp($phone, "Apni WhatsApp location share karne liye:
1. Attach (+) button tap karo
2. Location choose karo
3. Current Location share karo

Ya manually address type karo.");
            break;
        }
        $lat = null; $lng = null; $dist = null;

        if (!$isPickup) {
            $check = checkDeliveryArea($address);
            if (!$check['ok']) {
                $maxKm = getSetting('service_radius_km', 5);
                sendWhatsApp($phone, "Sorry ji, tera address saadi service area ({$maxKm} km) de bahar hai.\nDistance: {$check['distance']} km\n\n*pickup* type karo agar aap khud lene aaoge.");
                break;
            }
            $lat  = $check['lat']      ?? null;
            $lng  = $check['lng']      ?? null;
            $dist = $check['distance'] ?? null;
            $del  = calculateDeliveryCharge(cartTotal(getCart($phone)));
            updateSession($phone, ['delivery_charge' => $del]);
        }

        // Save temp address
        try { getDB()->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS temp_address TEXT DEFAULT NULL"); } catch(Exception $e){}
        try { getDB()->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS temp_lat DECIMAL(10,8) DEFAULT NULL"); } catch(Exception $e){}
        try { getDB()->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS temp_lng DECIMAL(11,8) DEFAULT NULL"); } catch(Exception $e){}
        try { getDB()->exec("ALTER TABLE sessions ADD COLUMN IF NOT EXISTS temp_dist DECIMAL(5,2) DEFAULT NULL"); } catch(Exception $e){}
        getDB()->prepare("UPDATE sessions SET temp_address=?, temp_lat=?, temp_lng=?, temp_dist=? WHERE phone=?")
               ->execute([$isPickup ? 'PICKUP' : $address, $lat, $lng, $dist, $phone]);

        updateSession($phone, ['state' => 'CHOOSE_PAYMENT']);

        $sess5    = getSession($phone);
        $cart     = getCart($phone);
        $discount = (float)($sess5['pending_discount'] ?? 0);
        $delivery = (float)($sess5['delivery_charge']  ?? 0);

        $codEnabled    = getSetting('cod_enabled', '1') === '1';
        $onlineEnabled = getSetting('online_payment_enabled', '1') === '1';
        $buttons = [];
        if ($onlineEnabled) $buttons['pay_online'] = 'Online Pay';
        if ($codEnabled)    $buttons['pay_cod']    = 'Cash on Delivery';

        sendWhatsApp($phone, cartSummary($cart, $discount, $delivery));
        if (!empty($buttons)) {
            sendButtonMessage($phone, "Payment method choose karo:", $buttons, "Payment",
                $codEnabled ? "COD available hai" : "Only online");
        } else {
            sendWhatsApp($phone, "Payment method set nahi. Admin se contact karo.");
        }
        break;

    // CHOOSE_PAYMENT
    case 'CHOOSE_PAYMENT':
        sendWhatsApp($phone, "Payment method upar wale buttons ton choose karo ji.");
        break;

    // AWAITING_PAYMENT / ORDER_PLACED
    case 'AWAITING_PAYMENT':
    case 'ORDER_PLACED':
        sendWhatsApp($phone, "Tera order pending hai.\nNaya order: *menu* type karo.");
        break;

    default:
        updateSession($phone, ['state' => 'CATEGORY_SELECT']);
        sendCategoryMenu($phone);
        break;
}

http_response_code(200);
echo "ok";
exit;

// ============================================
//  PLACE ORDER
// ============================================
function placeOrder($phone) {
    $session = getSession($phone);
    $cart    = getCart($phone);

    if (empty($cart)) {
        sendWhatsApp($phone, "Koi order nahi mila. *menu* type karo.");
        updateSession($phone, ['state' => 'CATEGORY_SELECT']);
        return;
    }

    $name     = $session['customer_name']    ?? 'Customer';
    $cPhone   = $session['customer_phone']   ?? null;
    $discount = (float)($session['pending_discount'] ?? 0);
    $delivery = (float)($session['delivery_charge']  ?? 0);
    $method   = $session['payment_method']   ?? 'online';
    $coupon   = $session['pending_coupon']   ?? null;
    $address  = $session['temp_address']     ?? 'N/A';
    $lat      = $session['temp_lat']         ?? null;
    $lng      = $session['temp_lng']         ?? null;
    $dist     = $session['temp_dist']        ?? null;

    $order = createOrder($phone, $name, $cart, [
        'discount'        => $discount,
        'delivery_charge' => $delivery,
        'payment_method'  => $method,
        'coupon_code'     => $coupon,
        'address'         => $address,
        'lat'             => $lat,
        'lng'             => $lng,
        'distance'        => $dist,
        'customer_phone'  => $cPhone,
    ]);

    // Coupon usage record
    if ($coupon && $discount > 0) {
        $cs = getDB()->prepare("SELECT id FROM coupons WHERE code=?");
        $cs->execute([$coupon]);
        $c = $cs->fetch();
        if ($c) applyCouponUsage($c['id'], $phone, $order['id'], $discount);
    }

    // Clear cart immediately (duplicate prevention)
    saveCart($phone, []);
    updateSession($phone, [
        'pending_coupon'   => null,
        'pending_discount' => 0,
        'delivery_charge'  => 0,
        'temp_address'     => null,
    ]);

    $restName = getSetting('restaurant_name', RESTAURANT_NAME);
    $estTime  = getSetting('estimated_time', '30-45');

    // Full order for notification
    $stmt = getDB()->prepare("SELECT * FROM orders WHERE id=?");
    $stmt->execute([$order['id']]);
    $fullOrder = $stmt->fetch();

    if ($method === 'cod') {
        getDB()->prepare("UPDATE orders SET payment_status='cod_pending', order_status='confirmed' WHERE id=?")
               ->execute([$order['id']]);
        updateSession($phone, ['state' => 'ORDER_PLACED']);

        // Confirmation message
        $msg  = "✅ *Order #{$order['order_number']} confirm ho gaya!*\n\n";
        $msg .= cartSummary($cart, $discount, $delivery) . "\n\n";
        $msg .= "📍 Address: {$address}\n";
        $msg .= "💵 Cash on Delivery: Rs.{$order['total']} ready rakhna ji\n";
        $msg .= "⏱ {$estTime} minutes mein milega\n\n";
        $msg .= "Shukriya *{$name}* ji — *{$restName}* choose karne da! 🙏";
        sendWhatsApp($phone, $msg);

        // Bill link
        $billToken = generateBillToken($order['id']);
        $billUrl   = getBillUrl($billToken);
        sendWhatsApp($phone, "🧾 *Apna bill dekho ji:*\n" . $billUrl . "\n\n_Print/Save laye Bill page te Print button use karo_");

        notifyRestaurant($fullOrder, $cart);

    } else {
        // Online payment — bill sirf payment confirm hone te bhejo (razorpay-webhook.php)
        updateSession($phone, ['state' => 'AWAITING_PAYMENT']);
        $payLink = createPaymentLink($order['id'], $order['order_number'], $order['total'], $phone, $name);
        if ($payLink) {
            $msg  = "🛒 *Order #{$order['order_number']} ready hai!*\n\n";
            $msg .= cartSummary($cart, $discount, $delivery) . "\n\n";
            $msg .= "📍 Address: {$address}\n\n";
            $msg .= "💳 *Payment karo:*\n{$payLink}\n\n";
            $msg .= "⏰ Link 15 minutes valid hai";
            sendWhatsApp($phone, $msg);
        } else {
            sendWhatsApp($phone, "Payment link nahi bani. Restaurant nu call karo: +" . getSetting('restaurant_phone', restPhone()));
        }
        notifyRestaurant($fullOrder, $cart);
    }
}

// Add item to cart (addons = [{id, name, price}])
function addToCart($phone, $item, $qty, &$cart = null, $addons = []) {
    $own = ($cart === null);
    if ($own) $cart = getCart($phone);

    // Items with addons → always new entry (different customization)
    // Items without addons → merge if same item exists without addons
    $found = false;
    if (empty($addons)) {
        foreach ($cart as &$ci) {
            if ($ci['id'] == $item['id'] && empty($ci['addons'])) {
                $ci['qty'] += $qty;
                $found = true;
                break;
            }
        }
        unset($ci);
    }

    if (!$found) {
        $entry = [
            'id'    => $item['id'],
            'name'  => $item['name'],
            'price' => (float)$item['price'],
            'qty'   => $qty,
        ];
        if (!empty($addons)) $entry['addons'] = $addons;
        $cart[] = $entry;
    }
    if ($own) saveCart($phone, $cart);
}
