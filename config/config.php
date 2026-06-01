<?php
// ============================================
//  config.php — SIRF DB CREDENTIALS
//  Baaki sab settings admin panel se manage hoti hain
//  Yeh file ek baar set karo, phir admin panel use karo
// ============================================

// --- Database (Hostinger MySQL) ---
define('DB_HOST',    'localhost');
define('DB_NAME',    'food_bot');
define('DB_USER',    'your_db_user');      // ← apna DB username
define('DB_PASS',    'your_db_password');  // ← apna DB password
define('DB_CHARSET', 'utf8mb4');

// --- Fallback constants (overridden by DB settings) ---
define('RESTAURANT_PHONE', '919XXXXXXXXX');
define('VERIFY_TOKEN',     'change_this_in_admin_settings');
define('WHATSAPP_TOKEN',   '');
define('WHATSAPP_PHONE_ID','');
define('RAZORPAY_KEY_ID',  '');
define('RAZORPAY_KEY_SECRET', '');
define('RAZORPAY_WEBHOOK_SECRET', '');
define('CURRENCY', 'INR');
define('BASE_URL',  'https://yourdomain.com/food-bot');
define('MIN_ORDER_AMOUNT', 100);

// ============================================
//  DATABASE CONNECTION
// ============================================
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log("DB Error: " . $e->getMessage());
            die(json_encode(['error' => 'Database connection failed']));
        }
    }
    return $pdo;
}
