<?php
// ============================================
//  MULTI-CLIENT INSTALLER — installer.php
//  Naye client laye automatic setup
//  IMPORTANT: Setup karne baad DELETE karo!
// ============================================

// Security — installer sirf direct access te kaam kare
define('INSTALLER_VERSION', '1.0');
$errors  = [];
$success = false;
$log     = [];

// ---- STEP 2: Process form ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['install'])) {

    $clientSlug  = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($_POST['client_slug'] ?? '')));
    $dbHost      = trim($_POST['db_host']     ?? 'localhost');
    $dbName      = trim($_POST['db_name']     ?? '');
    $dbUser      = trim($_POST['db_user']     ?? '');
    $dbPass      = trim($_POST['db_pass']     ?? '');
    $restName    = trim($_POST['rest_name']   ?? '');
    $restPhone   = preg_replace('/[^0-9]/', '', trim($_POST['rest_phone'] ?? ''));
    $waToken     = trim($_POST['wa_token']    ?? '');
    $waPhoneId   = trim($_POST['wa_phone_id'] ?? '');
    $verifyToken = trim($_POST['verify_token']?? 'verify_' . bin2hex(random_bytes(8)));
    $rzpKeyId    = trim($_POST['rzp_key_id']  ?? '');
    $rzpSecret   = trim($_POST['rzp_secret']  ?? '');
    $rzpWebhook  = trim($_POST['rzp_webhook'] ?? '');
    $baseUrl     = rtrim(trim($_POST['base_url'] ?? ''), '/');
    $adminPass   = trim($_POST['admin_pass']  ?? '');

    // Validation
    if (!$clientSlug)  $errors[] = "Client folder naam zaroori hai (a-z, 0-9, -, _)";
    if (!$dbName)      $errors[] = "Database naam zaroori hai";
    if (!$dbUser)      $errors[] = "Database user zaroori hai";
    if (!$restName)    $errors[] = "Restaurant naam zaroori hai";
    if (!$restPhone)   $errors[] = "Restaurant phone zaroori hai";
    if (!$baseUrl)     $errors[] = "Base URL zaroori hai";
    if (!$adminPass)   $errors[] = "Admin password zaroori hai";

    // Check folder conflict
    $targetDir = __DIR__ . '/' . $clientSlug;
    if (!$errors && is_dir($targetDir)) {
        $errors[] = "Folder '{$clientSlug}' pehle se maujood hai. Doosra naam rakho.";
    }

    if (!$errors) {
        // ---- Test DB Connection ----
        try {
            $testPdo = new PDO("mysql:host={$dbHost};charset=utf8mb4", $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            $log[] = "✅ Database server connect ho gaya";
        } catch (Exception $e) {
            $errors[] = "DB connection fail: " . $e->getMessage();
        }
    }

    if (!$errors) {
        // ---- Create Database ----
        try {
            $testPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $testPdo->exec("USE `{$dbName}`");
            $log[] = "✅ Database '{$dbName}' ready";
        } catch (Exception $e) {
            $errors[] = "Database create fail: " . $e->getMessage();
        }
    }

    if (!$errors) {
        // ---- Run SQL — Create all tables ----
        $sql = file_get_contents(__DIR__ . '/database.sql');
        // Remove CREATE DATABASE and USE lines (already done above)
        $sql = preg_replace('/^(CREATE DATABASE|USE)\s+.+;/im', '', $sql);
        // Split by semicolon and run
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        try {
            $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            foreach ($statements as $stmt) {
                if ($stmt) $pdo->exec($stmt);
            }
            $log[] = "✅ Tables create ho gayi (database.sql)";
        } catch (Exception $e) {
            $errors[] = "SQL error: " . $e->getMessage();
        }
    }

    if (!$errors) {
        // ---- Run database_update.sql ----
        $updateSql = file_get_contents(__DIR__ . '/database_update.sql');
        $updateSql = preg_replace('/^(CREATE DATABASE|USE)\s+.+;/im', '', $updateSql);
        $stmts2 = array_filter(array_map('trim', explode(';', $updateSql)));
        try {
            foreach ($stmts2 as $s) {
                if ($s) try { $pdo->exec($s); } catch(Exception $e2) { /* ignore duplicate key errors */ }
            }
            $log[] = "✅ Database update.sql run ho gaya";
        } catch (Exception $e) {
            $log[] = "⚠️ database_update partial: " . $e->getMessage();
        }
    }

    if (!$errors) {
        // ---- Insert default settings ----
        $settings = [
            'restaurant_name'          => $restName,
            'restaurant_phone'         => $restPhone,
            'whatsapp_token'           => $waToken,
            'whatsapp_phone_id'        => $waPhoneId,
            'verify_token'             => $verifyToken,
            'razorpay_key_id'          => $rzpKeyId,
            'razorpay_key_secret'      => $rzpSecret,
            'razorpay_webhook_secret'  => $rzpWebhook,
            'base_url'                 => $baseUrl . '/' . $clientSlug,
            'cod_enabled'              => '1',
            'online_payment_enabled'   => '1',
            'estimated_time'           => '30-45',
            'delivery_charge'          => '50',
            'free_delivery_above'      => '500',
            'min_order_amount'         => '100',
            'store_open'               => '1',
        ];
        foreach ($settings as $k => $v) {
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
                ->execute([$k, $v, $v]);
        }
        $log[] = "✅ Default settings save ho gayi";
    }

    if (!$errors) {
        // ---- Copy project files to new folder ----
        $skipItems = ['installer.php', '.git', '.claude', 'node_modules'];
        mkdir($targetDir, 0755, true);

        function copyDir($src, $dst, $skip = []) {
            $items = scandir($src);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                if (in_array($item, $skip)) continue;
                $s = $src . '/' . $item;
                $d = $dst . '/' . $item;
                if (is_dir($s)) {
                    mkdir($d, 0755, true);
                    copyDir($s, $d);
                } else {
                    copy($s, $d);
                }
            }
        }
        copyDir(__DIR__, $targetDir, $skipItems);
        $log[] = "✅ Files copy ho gayi → /{$clientSlug}/";
    }

    if (!$errors) {
        // ---- Write config.php for new client ----
        $configContent = '<?php
// ============================================
//  config.php — ' . htmlspecialchars($restName) . '
//  Auto-generated by installer on ' . date('d M Y') . '
// ============================================

date_default_timezone_set(\'Asia/Kolkata\');

// Database
define(\'DB_HOST\',    \'' . addslashes($dbHost) . '\');
define(\'DB_NAME\',    \'' . addslashes($dbName) . '\');
define(\'DB_USER\',    \'' . addslashes($dbUser) . '\');
define(\'DB_PASS\',    \'' . addslashes($dbPass) . '\');
define(\'DB_CHARSET\', \'utf8mb4\');

// Fallback constants
define(\'RESTAURANT_NAME\',      \'' . addslashes($restName) . '\');
define(\'RESTAURANT_PHONE\',     \'' . addslashes($restPhone) . '\');
define(\'VERIFY_TOKEN\',         \'' . addslashes($verifyToken) . '\');
define(\'WHATSAPP_TOKEN\',       \'' . addslashes($waToken) . '\');
define(\'WHATSAPP_PHONE_ID\',    \'' . addslashes($waPhoneId) . '\');
define(\'RAZORPAY_KEY_ID\',      \'' . addslashes($rzpKeyId) . '\');
define(\'RAZORPAY_KEY_SECRET\',  \'' . addslashes($rzpSecret) . '\');
define(\'RAZORPAY_WEBHOOK_SECRET\',\'' . addslashes($rzpWebhook) . '\');
define(\'CURRENCY\',  \'INR\');
define(\'BASE_URL\',  \'' . addslashes($baseUrl . '/' . $clientSlug) . '\');
define(\'MIN_ORDER_AMOUNT\', 100);

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
            die(json_encode([\'error\' => \'Database connection failed\']));
        }
    }
    return $pdo;
}
';
        file_put_contents($targetDir . '/config/config.php', $configContent);
        $log[] = "✅ config.php write ho gaya";
    }

    if (!$errors) {
        // ---- Update admin password in index.php ----
        $adminFile = $targetDir . '/admin/index.php';
        if (file_exists($adminFile)) {
            $content = file_get_contents($adminFile);
            $content = preg_replace(
                "/\\\$adminPass\s*=\s*'[^']*'/",
                "\$adminPass = '" . addslashes($adminPass) . "'",
                $content
            );
            file_put_contents($adminFile, $content);
            $log[] = "✅ Admin password set ho gaya";
        }

        // Same for menu.php, bills.php etc
        foreach (['admin/menu.php','admin/bills.php','admin/coupons.php','admin/store-hours.php','admin/settings.php'] as $af) {
            $f = $targetDir . '/' . $af;
            if (file_exists($f)) {
                $c = file_get_contents($f);
                $c = preg_replace("/\\\$adminPass\s*=\s*'[^']*'/", "\$adminPass = '" . addslashes($adminPass) . "'", $c);
                file_put_contents($f, $c);
            }
        }
    }

    if (!$errors) {
        $success = true;
        $log[] = "🎉 Setup mukammal! Client ready hai.";
    }
}
?>
<!DOCTYPE html>
<html lang="pa">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>WhatsApp Food Bot — Installer</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Plus Jakarta Sans',sans-serif;background:#0f1117;color:#eef0f4;min-height:100vh;padding:30px 16px}
.wrap{max-width:780px;margin:0 auto}
.header{text-align:center;margin-bottom:32px}
.header h1{font-size:26px;font-weight:800;margin-bottom:6px}
.header p{color:#7a8099;font-size:14px}
.warning{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#f87171;padding:12px 16px;border-radius:10px;font-size:13px;margin-bottom:24px;text-align:center;font-weight:600}
.card{background:#181c25;border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:24px;margin-bottom:16px}
.card-title{font-size:13px;font-weight:700;color:#7a8099;text-transform:uppercase;letter-spacing:1px;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:600px){.grid{grid-template-columns:1fr}}
.field{display:flex;flex-direction:column;gap:5px}
.field.full{grid-column:1/-1}
label{font-size:11px;font-weight:700;color:#7a8099;letter-spacing:.4px}
input{background:#1f2433;border:1px solid rgba(255,255,255,.1);border-radius:8px;padding:10px 12px;color:#eef0f4;font-size:13px;font-family:inherit;outline:none;transition:border-color .2s}
input:focus{border-color:#1db954}
.hint{font-size:10px;color:#4a5568;margin-top:2px;line-height:1.4}
.btn{width:100%;padding:14px;border-radius:10px;border:none;background:#1db954;color:#fff;font-size:15px;font-weight:800;cursor:pointer;font-family:inherit;transition:background .2s;margin-top:8px}
.btn:hover{background:#17a349}
.error-box{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:10px;padding:14px;margin-bottom:16px}
.error-box div{font-size:13px;color:#f87171;margin-bottom:4px}
.log-box{background:#0f1117;border:1px solid rgba(255,255,255,.06);border-radius:10px;padding:16px;margin-bottom:16px}
.log-box div{font-size:13px;margin-bottom:6px;font-family:monospace}
.success-card{background:rgba(29,185,84,.08);border:2px solid rgba(29,185,84,.3);border-radius:16px;padding:28px;text-align:center}
.success-card h2{font-size:22px;font-weight:800;color:#1db954;margin-bottom:12px}
.success-card p{font-size:14px;color:#7a8099;margin-bottom:6px}
.link-box{background:#0f1117;border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:12px 16px;font-family:monospace;font-size:13px;color:#1db954;margin:8px 0;word-break:break-all}
.delete-warn{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:10px;padding:14px;margin-top:20px;font-size:13px;color:#f87171;font-weight:600}
</style>
</head>
<body>
<div class="wrap">

<div class="header">
  <div style="font-size:48px;margin-bottom:12px">🍽️</div>
  <h1>WhatsApp Food Bot Installer</h1>
  <p>Naye client laye automatic setup — v<?= INSTALLER_VERSION ?></p>
</div>

<div class="warning">
  ⚠️ Setup mukammal hone ke baad <strong>installer.php DELETE karo</strong> — security risk hai!
</div>

<?php if (!empty($errors)): ?>
<div class="error-box">
  <?php foreach ($errors as $e): ?>
    <div>❌ <?= htmlspecialchars($e) ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($log)): ?>
<div class="log-box">
  <?php foreach ($log as $l): ?>
    <div><?= htmlspecialchars($l) ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($success):
    $clientSlug = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($_POST['client_slug'] ?? '')));
    $baseUrl    = rtrim(trim($_POST['base_url'] ?? ''), '/');
    $restName   = trim($_POST['rest_name'] ?? '');
    $adminUrl   = $baseUrl . '/' . $clientSlug . '/admin/index.php';
    $webhookUrl = $baseUrl . '/' . $clientSlug . '/webhook.php';
?>
<div class="success-card">
  <div style="font-size:48px;margin-bottom:12px">🎉</div>
  <h2><?= htmlspecialchars($restName) ?> — Ready!</h2>

  <p style="margin-top:16px;font-weight:700;color:#eef0f4">Admin Panel:</p>
  <div class="link-box"><?= htmlspecialchars($adminUrl) ?></div>

  <p style="font-weight:700;color:#eef0f4">WhatsApp Webhook URL:</p>
  <div class="link-box"><?= htmlspecialchars($webhookUrl) ?></div>

  <p style="font-size:12px;margin-top:16px;color:#7a8099">
    Meta Developer Console te yeh webhook URL add karo<br>
    Verify Token: <strong style="color:#1db954"><?= htmlspecialchars($_POST['verify_token'] ?? '') ?></strong>
  </p>

  <div class="delete-warn">
    🔴 ZAROORI: Ab <code>installer.php</code> server ton DELETE karo!<br>
    <code>rm <?= htmlspecialchars(__FILE__) ?></code>
  </div>
</div>

<?php else: ?>

<form method="POST">
<input type="hidden" name="install" value="1">

<!-- Client Info -->
<div class="card">
  <div class="card-title">🏪 Client / Restaurant Info</div>
  <div class="grid">
    <div class="field full">
      <label>FOLDER NAAM (URL mein dikhega) *</label>
      <input type="text" name="client_slug" placeholder="ethics-moga" pattern="[a-z0-9_-]+" value="<?= htmlspecialchars($_POST['client_slug'] ?? '') ?>" required>
      <div class="hint">Sirf small letters, numbers, dash — e.g. "sharma-dhaba", "pizza-point"</div>
    </div>
    <div class="field">
      <label>RESTAURANT NAAM *</label>
      <input type="text" name="rest_name" placeholder="Ethics Restaurant" value="<?= htmlspecialchars($_POST['rest_name'] ?? '') ?>" required>
    </div>
    <div class="field">
      <label>WHATSAPP NUMBER (country code nal) *</label>
      <input type="text" name="rest_phone" placeholder="919876543210" value="<?= htmlspecialchars($_POST['rest_phone'] ?? '') ?>" required>
    </div>
    <div class="field full">
      <label>BASE URL (server da root URL) *</label>
      <input type="url" name="base_url" placeholder="https://site7.officialdigitalmarketing.in" value="<?= htmlspecialchars($_POST['base_url'] ?? '') ?>" required>
      <div class="hint">Final URL bana: base_url/folder_naam — bina trailing slash</div>
    </div>
  </div>
</div>

<!-- Database -->
<div class="card">
  <div class="card-title">🗄️ Database Settings</div>
  <div class="grid">
    <div class="field">
      <label>DB HOST</label>
      <input type="text" name="db_host" placeholder="localhost" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>">
    </div>
    <div class="field">
      <label>DATABASE NAAM *</label>
      <input type="text" name="db_name" placeholder="client1_foodbot" value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>" required>
      <div class="hint">Har client laye alag DB naam rakho</div>
    </div>
    <div class="field">
      <label>DB USERNAME *</label>
      <input type="text" name="db_user" placeholder="root" value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>" required>
    </div>
    <div class="field">
      <label>DB PASSWORD</label>
      <input type="password" name="db_pass" placeholder="••••••••" value="<?= htmlspecialchars($_POST['db_pass'] ?? '') ?>">
    </div>
  </div>
</div>

<!-- WhatsApp API -->
<div class="card">
  <div class="card-title">📱 WhatsApp API Keys</div>
  <div class="grid">
    <div class="field full">
      <label>WHATSAPP ACCESS TOKEN</label>
      <input type="text" name="wa_token" placeholder="EAAxxxxxxxx..." value="<?= htmlspecialchars($_POST['wa_token'] ?? '') ?>">
      <div class="hint">Meta Developer Console → System User → Token</div>
    </div>
    <div class="field">
      <label>PHONE NUMBER ID</label>
      <input type="text" name="wa_phone_id" placeholder="123456789" value="<?= htmlspecialchars($_POST['wa_phone_id'] ?? '') ?>">
    </div>
    <div class="field">
      <label>WEBHOOK VERIFY TOKEN</label>
      <input type="text" name="verify_token" placeholder="auto-generate hoga" value="<?= htmlspecialchars($_POST['verify_token'] ?? '') ?>">
      <div class="hint">Khali chhado — auto bana dega</div>
    </div>
  </div>
</div>

<!-- Razorpay -->
<div class="card">
  <div class="card-title">💳 Razorpay Keys (optional)</div>
  <div class="grid">
    <div class="field">
      <label>RAZORPAY KEY ID</label>
      <input type="text" name="rzp_key_id" placeholder="rzp_live_xxxxx" value="<?= htmlspecialchars($_POST['rzp_key_id'] ?? '') ?>">
    </div>
    <div class="field">
      <label>RAZORPAY KEY SECRET</label>
      <input type="password" name="rzp_secret" placeholder="••••••••" value="<?= htmlspecialchars($_POST['rzp_secret'] ?? '') ?>">
    </div>
    <div class="field full">
      <label>RAZORPAY WEBHOOK SECRET</label>
      <input type="text" name="rzp_webhook" placeholder="optional" value="<?= htmlspecialchars($_POST['rzp_webhook'] ?? '') ?>">
    </div>
  </div>
</div>

<!-- Admin -->
<div class="card">
  <div class="card-title">🔐 Admin Panel</div>
  <div class="grid">
    <div class="field full">
      <label>ADMIN PASSWORD *</label>
      <input type="password" name="admin_pass" placeholder="Strong password rakho" required>
      <div class="hint">Yeh client nu dena hoga — strong rakho</div>
    </div>
  </div>
</div>

<button type="submit" class="btn">🚀 Install Karo — New Client Setup</button>
</form>

<?php endif; ?>

</div>
</body>
</html>
