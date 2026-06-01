<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
session_start();
if (!($_SESSION['admin'] ?? false)) { header('Location: index.php'); exit; }
$db = getDB(); $msg = '';

if ($_POST['action'] ?? '' === 'save_settings') {
    $keys = [
        // Restaurant
        'restaurant_name','restaurant_phone','restaurant_address','estimated_time',
        // WhatsApp API Keys
        'whatsapp_token','whatsapp_phone_id','verify_token',
        // Razorpay Keys
        'razorpay_key_id','razorpay_key_secret','razorpay_webhook_secret','base_url',
        // Google
        'google_maps_key','google_review_link','review_after_minutes',
        // Service Area
        'restaurant_lat','restaurant_lng','service_radius_km',
        // Delivery
        'delivery_charge','free_delivery_above','min_order_amount',
        // Payment
        'cod_enabled','online_payment_enabled',
        // GST
        'gst_enabled','gst_percent','gst_included','restaurant_gstin','bill_footer_text',
    ];
    foreach ($keys as $key) {
        $val = trim($_POST[$key] ?? '');
        // Checkboxes
        if (in_array($key, ['cod_enabled','online_payment_enabled','gst_enabled','gst_included'])) {
            $val = isset($_POST[$key]) ? '1' : '0';
        }
        $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
           ->execute([$key, $val, $val]);
    }
    $msg = "success";
}

$rows = $db->query("SELECT * FROM settings")->fetchAll();
$s = []; foreach ($rows as $r) $s[$r['setting_key']] = $r['setting_value'];
function sv($s,$k,$d=''){return htmlspecialchars($s[$k]??$d);}
function sc($s,$k){return ($s[$k]??'0')==='1'?'checked':'';}
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Settings</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--green:#1db954;--green2:#17a349;--red:#e53935;--amber:#f59e0b;--blue:#3b82f6;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;background:#f1f5f9;color:#111;min-height:100vh;}
.header{background:#fff;border-bottom:1px solid #e5e7eb;box-shadow:0 1px 3px rgba(0,0,0,.06);padding:0 16px;display:flex;align-items:center;gap:12px;height:58px;position:sticky;top:0;z-index:100;}
.header-nav{margin-left:auto;display:flex;gap:4px;overflow-x:auto;-webkit-overflow-scrolling:touch;}
.header-nav a{font-size:12px;font-weight:600;padding:6px 10px;border-radius:8px;text-decoration:none;color:#6b7280;white-space:nowrap;}
.header-nav a:hover{background:#f3f4f6;color:#111;}
.header-nav a.active{background:var(--green);color:#fff;}
.page{max-width:860px;margin:0 auto;padding:24px 16px;}
.flash{padding:12px 16px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:20px;}
.flash.success{background:#f0fdf4;border:1px solid #86efac;color:#16a34a;}
.section{background:#fff;border:1px solid #e5e7eb;border-radius:14px;margin-bottom:16px;overflow:hidden;}
.section-head{display:flex;align-items:center;gap:12px;padding:18px 22px;border-bottom:1px solid #e5e7eb;cursor:pointer;user-select:none;}
.section-head:hover{background:#fafafa;}
.section-icon{font-size:20px;width:36px;text-align:center;}
.section-title{font-size:14px;font-weight:700;color:#111;}
.section-sub{font-size:11px;color:#6b7280;margin-top:2px;}
.chevron{margin-left:auto;color:#6b7280;font-size:12px;transition:transform .2s;}
.section-body{padding:22px;display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px;}
.section-body.collapsed{display:none;}
.field{display:flex;flex-direction:column;gap:5px;}
.field.full{grid-column:1/-1;}
.field label{font-size:11px;font-weight:700;color:#6b7280;letter-spacing:.5px;text-transform:uppercase;}
.field input,.field select{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;color:#111;font-size:13px;font-family:inherit;outline:none;transition:border-color .2s;}
.field input:focus,.field select:focus{border-color:var(--green);}
.field input[type=password]{letter-spacing:2px;}
.field small{font-size:11px;color:#6b7280;line-height:1.4;}
.field a{color:var(--green);font-size:11px;}
.key-field{position:relative;}
.key-field input{padding-right:40px;}
.eye-btn{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:#6b7280;cursor:pointer;font-size:14px;padding:4px;}
.toggle-row{grid-column:1/-1;display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:1px solid #f3f4f6;}
.toggle-row:last-of-type{border-bottom:none;}
.toggle-label{font-size:13px;font-weight:600;color:#111;}
.toggle-sub{font-size:11px;color:#6b7280;margin-top:2px;}
.toggle{position:relative;width:42px;height:24px;flex-shrink:0;}
.toggle input{opacity:0;width:0;height:0;}
.slider{position:absolute;inset:0;background:#e5e7eb;border:1px solid #d1d5db;border-radius:24px;cursor:pointer;transition:.3s;}
.slider:before{content:'';position:absolute;width:16px;height:16px;left:3px;top:3px;background:#9ca3af;border-radius:50%;transition:.3s;}
.toggle input:checked+.slider{background:var(--green);border-color:var(--green);}
.toggle input:checked+.slider:before{transform:translateX(18px);background:#fff;}
.save-bar{position:sticky;bottom:0;background:#fff;border-top:1px solid #e5e7eb;padding:16px 20px;display:flex;align-items:center;gap:14px;margin-top:20px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 22px;border-radius:8px;border:none;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;}
.btn-green{background:var(--green);color:#fff;}
.btn-green:hover{background:var(--green2);}
.info-box{grid-column:1/-1;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px 14px;font-size:12px;color:#92400e;line-height:1.6;}
.gst-preview{grid-column:1/-1;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:12px 14px;font-size:12px;color:#16a34a;}

@media(max-width:640px){
  .page{padding:16px 12px;}
  .section-body{grid-template-columns:1fr;}
  .section-head{padding:14px 16px;}
  .header-nav a{padding:5px 7px;font-size:11px;}
  .save-bar{padding:12px 16px;}
}
</style>
</head><body>

<div class="header">
  <span style="font-size:22px">🍽</span>
  <div><div style="font-size:15px;font-weight:700;color:#111"><?= sv($s,'restaurant_name','Restaurant') ?></div>
  <div style="font-size:11px;color:#6b7280">Settings</div></div>
  <nav class="header-nav">
    <a href="index.php">📋 Orders</a>
    <a href="menu.php">🍛 Menu</a>
    <a href="store-hours.php">🕐 Hours</a>
    <a href="coupons.php">🏷 Coupons</a>
    <a href="settings.php" class="active">⚙️ Settings</a>
  </nav>
</div>

<div class="page">
<?php if ($msg === 'success'): ?>
<div class="flash success">✅ Settings save ho gayi! Changes turant apply ho gaye hain.</div>
<?php endif; ?>

<form method="POST" id="settingsForm">
<input type="hidden" name="action" value="save_settings">

<!-- ===== RESTAURANT INFO ===== -->
<div class="section">
  <div class="section-head" onclick="toggle(this)">
    <span class="section-icon">🏪</span>
    <div><div class="section-title">Restaurant Info</div><div class="section-sub">Basic details</div></div>
    <span class="chevron">▼</span>
  </div>
  <div class="section-body">
    <div class="field"><label>Restaurant Naam</label><input type="text" name="restaurant_name" value="<?= sv($s,'restaurant_name') ?>"></div>
    <div class="field"><label>WhatsApp Number (country code nal)</label><input type="text" name="restaurant_phone" value="<?= sv($s,'restaurant_phone') ?>" placeholder="919876543210"></div>
    <div class="field full"><label>Address</label><input type="text" name="restaurant_address" value="<?= sv($s,'restaurant_address') ?>" placeholder="Moga, Punjab"></div>
    <div class="field"><label>Estimated Delivery Time</label><input type="text" name="estimated_time" value="<?= sv($s,'estimated_time','30-45') ?>" placeholder="30-45"></div>
  </div>
</div>

<!-- ===== WHATSAPP API KEYS ===== -->
<div class="section">
  <div class="section-head" onclick="toggle(this)">
    <span class="section-icon">📱</span>
    <div><div class="section-title">WhatsApp API Keys</div><div class="section-sub">Meta Developer Console se mildi hain</div></div>
    <span class="chevron">▼</span>
  </div>
  <div class="section-body collapsed">
    <div class="info-box">
      ⚠️ <strong>Important:</strong> Yeh keys bahut sensitive hain. Kisi nal share mat karo.<br>
      Get from: <strong>developers.facebook.com → Your App → WhatsApp → API Setup</strong>
    </div>
    <div class="field full"><label>WhatsApp Access Token</label>
      <div class="key-field">
        <input type="password" name="whatsapp_token" value="<?= sv($s,'whatsapp_token') ?>" placeholder="EAAxxxxxxxx..." autocomplete="off">
        <button type="button" class="eye-btn" onclick="togglePass(this)">👁</button>
      </div>
      <small>Meta Developer Console → System User → Generate Token</small>
    </div>
    <div class="field"><label>Phone Number ID</label>
      <input type="text" name="whatsapp_phone_id" value="<?= sv($s,'whatsapp_phone_id') ?>" placeholder="12345678901234">
      <small>API Setup page te milda hai</small>
    </div>
    <div class="field"><label>Webhook Verify Token</label>
      <input type="text" name="verify_token" value="<?= sv($s,'verify_token') ?>" placeholder="fb_verify_xxxxx">
      <small>Jo tusi Meta Webhook te set kita si</small>
    </div>
    <div class="field full"><label>Base URL (Webhook liye)</label>
      <input type="url" name="base_url" value="<?= sv($s,'base_url') ?>" placeholder="https://yourdomain.com/food-bot">
      <small>Tera server URL — bina trailing slash</small>
    </div>
  </div>
</div>

<!-- ===== RAZORPAY KEYS ===== -->
<div class="section">
  <div class="section-head" onclick="toggle(this)">
    <span class="section-icon">💳</span>
    <div><div class="section-title">Razorpay Payment Keys</div><div class="section-sub">Online payment ke liye</div></div>
    <span class="chevron">▼</span>
  </div>
  <div class="section-body collapsed">
    <div class="info-box">
      Get from: <strong>dashboard.razorpay.com → Settings → API Keys</strong>
    </div>
    <div class="field"><label>Razorpay Key ID</label>
      <input type="text" name="razorpay_key_id" value="<?= sv($s,'razorpay_key_id') ?>" placeholder="rzp_live_xxxxxxxxxx">
    </div>
    <div class="field"><label>Razorpay Key Secret</label>
      <div class="key-field">
        <input type="password" name="razorpay_key_secret" value="<?= sv($s,'razorpay_key_secret') ?>" placeholder="xxxxxxxxxxxxxxxx" autocomplete="off">
        <button type="button" class="eye-btn" onclick="togglePass(this)">👁</button>
      </div>
    </div>
    <div class="field full"><label>Razorpay Webhook Secret</label>
      <div class="key-field">
        <input type="password" name="razorpay_webhook_secret" value="<?= sv($s,'razorpay_webhook_secret') ?>" placeholder="Webhook secret" autocomplete="off">
        <button type="button" class="eye-btn" onclick="togglePass(this)">👁</button>
      </div>
      <small>dashboard.razorpay.com → Settings → Webhooks → Secret</small>
    </div>
    <div class="toggle-row">
      <div><div class="toggle-label">💳 Online Payment Enable</div></div>
      <label class="toggle"><input type="checkbox" name="online_payment_enabled" value="1" <?= sc($s,'online_payment_enabled') ?>><span class="slider"></span></label>
    </div>
    <div class="toggle-row">
      <div><div class="toggle-label">💵 Cash on Delivery Enable</div></div>
      <label class="toggle"><input type="checkbox" name="cod_enabled" value="1" <?= sc($s,'cod_enabled') ?>><span class="slider"></span></label>
    </div>
  </div>
</div>

<!-- ===== GST ===== -->
<div class="section">
  <div class="section-head" onclick="toggle(this)">
    <span class="section-icon">🧾</span>
    <div><div class="section-title">GST Settings</div><div class="section-sub">Tax on/off aur percentage set karo</div></div>
    <span class="chevron">▼</span>
  </div>
  <div class="section-body">
    <div class="toggle-row">
      <div>
        <div class="toggle-label">GST Enable Karo</div>
        <div class="toggle-sub">Orders te GST automatically calculate hogi</div>
      </div>
      <label class="toggle"><input type="checkbox" name="gst_enabled" value="1" <?= sc($s,'gst_enabled') ?> onchange="updateGSTPreview()"><span class="slider"></span></label>
    </div>
    <div class="toggle-row">
      <div>
        <div class="toggle-label">GST Price Mein Included Hai?</div>
        <div class="toggle-sub">ON = price mein GST pehle se hai | OFF = price upar GST add hogi</div>
      </div>
      <label class="toggle"><input type="checkbox" name="gst_included" value="1" <?= sc($s,'gst_included') ?> onchange="updateGSTPreview()"><span class="slider"></span></label>
    </div>
    <div class="field">
      <label>GST Percentage (%)</label>
      <input type="number" name="gst_percent" id="gstPct" value="<?= sv($s,'gst_percent','5') ?>" min="0" max="28" step="0.5" oninput="updateGSTPreview()">
      <small>Restaurant food: usually 5% | AC restaurant: 18%</small>
    </div>
    <div class="field">
      <label>GSTIN Number (optional)</label>
      <input type="text" name="restaurant_gstin" value="<?= sv($s,'restaurant_gstin') ?>" placeholder="22AAAAA0000A1Z5" maxlength="15" style="text-transform:uppercase" oninput="this.value=this.value.toUpperCase()">
      <small>Bill te show hoga — agar registered nahi ta khali chhado</small>
    </div>
    <div class="field full">
      <label>Bill Footer Text</label>
      <input type="text" name="bill_footer_text" value="<?= sv($s,'bill_footer_text','Shukriya! Dobara aana ji') ?>" placeholder="Shukriya! Dobara aana ji">
      <small>Har customer bill de neeche yeh text dikhega</small>
    </div>
    <div class="gst-preview" id="gstPreview">
      💡 GST preview: Rs.500 order te GST = Rs.25 | Total = Rs.525
    </div>
  </div>
</div>

<!-- ===== DELIVERY ===== -->
<div class="section">
  <div class="section-head" onclick="toggle(this)">
    <span class="section-icon">🚚</span>
    <div><div class="section-title">Delivery Charges</div><div class="section-sub">Slab based delivery</div></div>
    <span class="chevron">▼</span>
  </div>
  <div class="section-body">
    <div class="field"><label>Delivery Charge (Rs.)</label><input type="number" name="delivery_charge" value="<?= sv($s,'delivery_charge','50') ?>" min="0"></div>
    <div class="field"><label>Free Delivery Above (Rs.)</label><input type="number" name="free_delivery_above" value="<?= sv($s,'free_delivery_above','500') ?>" min="0"><small>0 rakho = hamesha charge</small></div>
    <div class="field"><label>Minimum Order (Rs.)</label><input type="number" name="min_order_amount" value="<?= sv($s,'min_order_amount','100') ?>" min="0"></div>
  </div>
</div>

<!-- ===== SERVICE AREA ===== -->
<div class="section">
  <div class="section-head" onclick="toggle(this)">
    <span class="section-icon">📍</span>
    <div><div class="section-title">Service Area</div><div class="section-sub">Customer address auto-verify</div></div>
    <span class="chevron">▼</span>
  </div>
  <div class="section-body collapsed">
    <div class="field"><label>Restaurant Latitude</label><input type="text" name="restaurant_lat" value="<?= sv($s,'restaurant_lat') ?>" placeholder="30.8145"><small>Google Maps → restaurant → right click → coordinates</small></div>
    <div class="field"><label>Restaurant Longitude</label><input type="text" name="restaurant_lng" value="<?= sv($s,'restaurant_lng') ?>" placeholder="75.1683"></div>
    <div class="field"><label>Service Radius (KM)</label><input type="number" name="service_radius_km" value="<?= sv($s,'service_radius_km','5') ?>" min="1" max="100" step="0.5"></div>
    <div class="field full"><label>Google Maps API Key</label><input type="text" name="google_maps_key" value="<?= sv($s,'google_maps_key') ?>" placeholder="AIzaxxxxxxx"><small><a href="https://console.cloud.google.com" target="_blank">console.cloud.google.com</a> → Geocoding API enable karo</small></div>
  </div>
</div>

<!-- ===== GOOGLE REVIEW ===== -->
<div class="section">
  <div class="section-head" onclick="toggle(this)">
    <span class="section-icon">⭐</span>
    <div><div class="section-title">Google Review</div><div class="section-sub">Delivery baad auto-send</div></div>
    <span class="chevron">▼</span>
  </div>
  <div class="section-body collapsed">
    <div class="field full"><label>Google Review Link</label><input type="url" name="google_review_link" value="<?= sv($s,'google_review_link') ?>" placeholder="https://g.page/r/xxxxx/review"></div>
    <div class="field"><label>Send After (minutes)</label><input type="number" name="review_after_minutes" value="<?= sv($s,'review_after_minutes','60') ?>" min="0"><small>0 = nahi bhejna</small></div>
  </div>
</div>

<!-- Save Bar -->
<div class="save-bar">
  <button type="submit" class="btn btn-green">💾 Save All Settings</button>
  <span style="font-size:12px;color:var(--muted)">Changes turant apply hote hain — code update karne di zaroorat nahi</span>
</div>

</form>
</div>

<script>
function toggle(head) {
    const body = head.nextElementSibling;
    const chev = head.querySelector('.chevron');
    body.classList.toggle('collapsed');
    chev.style.transform = body.classList.contains('collapsed') ? '' : 'rotate(180deg)';
}

function togglePass(btn) {
    const inp = btn.previousElementSibling;
    inp.type  = inp.type === 'password' ? 'text' : 'password';
    btn.textContent = inp.type === 'password' ? '👁' : '🙈';
}

function updateGSTPreview() {
    const pct      = parseFloat(document.getElementById('gstPct').value) || 0;
    const enabled  = document.querySelector('[name=gst_enabled]').checked;
    const included = document.querySelector('[name=gst_included]').checked;
    const preview  = document.getElementById('gstPreview');
    const sample   = 500;

    if (!enabled || pct === 0) {
        preview.textContent = '💡 GST disabled hai — orders pe koi tax nahi lagega.';
        return;
    }

    let gst, total;
    if (included) {
        gst   = Math.round(sample - (sample * 100 / (100 + pct)));
        total = sample;
        preview.innerHTML = `💡 GST Preview (included): Rs.${sample} order mein Rs.${gst} GST already hai | Customer Rs.${total} dega`;
    } else {
        gst   = Math.round(sample * pct / 100);
        total = sample + gst;
        preview.innerHTML = `💡 GST Preview (added on top): Rs.${sample} + Rs.${gst} GST = Rs.${total} total | Customer Rs.${total} dega`;
    }
}

// Initial preview
updateGSTPreview();
</script>
</body></html>
