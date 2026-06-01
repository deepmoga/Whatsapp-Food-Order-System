# 🍽 WhatsApp Food Ordering Bot — Setup Guide
## PHP + MySQL + Meta Cloud API + Razorpay

---

## 📁 File Structure

```
food-bot/
├── config/
│   └── config.php          ← Saari credentials yahan
├── includes/
│   └── helpers.php         ← Core functions
├── admin/
│   └── index.php           ← Order management panel
├── webhook.php             ← WhatsApp messages aande hain yahan
├── razorpay-webhook.php    ← Payment confirmation
└── database.sql            ← Database setup
```

---

## STEP 1 — Hostinger MySQL Setup

1. Hostinger hPanel → **Databases → MySQL Databases**
2. Naya database banao: `food_bot`
3. Naya user banao, usse database nal link karo
4. **phpMyAdmin** kholo → `food_bot` select karo
5. **SQL tab** te `database.sql` da content paste karo → **Go**

---

## STEP 2 — Files Upload

1. Hostinger **File Manager** kholo
2. `public_html` te naya folder banao: `food-bot`
3. Saari files upload karo us folder mein
4. Structure aisi honi chahidi:
   ```
   public_html/food-bot/webhook.php
   public_html/food-bot/config/config.php
   ... etc
   ```

---

## STEP 3 — Meta WhatsApp Cloud API Setup

### 3a. App Banao
1. https://developers.facebook.com → **My Apps → Create App**
2. Type: **Business** → App name: "Food Bot"
3. Left menu: **WhatsApp → API Setup**

### 3b. Phone Number
1. **Add phone number** karo (ya test number use karo pehle)
2. **Phone Number ID** note karo (config.php mein pauna hai)

### 3c. Access Token
1. **System User** banao: Business Settings → System Users
2. **Permanent Token** generate karo (WhatsApp permissions de)
3. Token config.php mein pao

### 3d. Webhook Set Karo
1. WhatsApp → Configuration → **Webhooks**
2. Callback URL: `https://yourdomain.com/food-bot/webhook.php`
3. Verify Token: jo config.php mein rakha hai (`VERIFY_TOKEN`)
4. **Subscribe** karo: `messages` checkbox

---

## STEP 4 — Razorpay Setup

1. https://dashboard.razorpay.com → **Settings → API Keys**
2. **Generate Key** → Key ID aur Secret config.php mein pao
3. **Webhooks** (Settings → Webhooks):
   - URL: `https://yourdomain.com/food-bot/razorpay-webhook.php`
   - Secret: koi v random string (config.php da `RAZORPAY_WEBHOOK_SECRET`)
   - Events: `payment_link.paid` ✓ check karo

---

## STEP 5 — config.php Fill Karo

```php
define('DB_USER',    'your_hostinger_db_user');
define('DB_PASS',    'your_db_password');
define('WHATSAPP_TOKEN',    'EAAxxxxxxxx...');
define('WHATSAPP_PHONE_ID', '12345678901234');
define('VERIFY_TOKEN',      'mySecretToken123');
define('RAZORPAY_KEY_ID',     'rzp_live_xxxxx');
define('RAZORPAY_KEY_SECRET', 'xxxxxxxxxxxxxxx');
define('RAZORPAY_WEBHOOK_SECRET', 'myWebhookSecret');
define('RESTAURANT_NAME',  'Tera Restaurant');
define('RESTAURANT_PHONE', '919XXXXXXXXX');  // country code + number, no +
define('BASE_URL', 'https://yourdomain.com/food-bot');
```

---

## STEP 6 — Test Karo

1. Apne WhatsApp te `Hi` bhejo restaurant number te
2. Menu aana chahida
3. Category select karo → Items dikhen
4. Item add karo → Cart confirm karo
5. Payment link aaye → Pay karo
6. Customer + restaurant dono nu confirmation aaye ✅

---

## Admin Panel

Browser te kholo:
```
https://yourdomain.com/food-bot/admin/
Password: admin123  (admin/index.php mein change karo!)
```

Features:
- Live orders dekho
- Order status update karo (Preparing / Ready / Delivered)
- Revenue stats
- Auto-refresh every 30 seconds

---

## Customer Commands (WhatsApp te)

| Command | Kaam |
|---------|------|
| `hi` / `hello` / `menu` | Bot shuru karo |
| `1` to `5` | Category select karo |
| `3` or `3 2` | Item add karo (id + qty) |
| `cart` | Cart dekho |
| `confirm` | Order place karo |
| `clear` | Cart saaf karo |
| `help` | Commands list |

---

## Menu Update Karna

phpMyAdmin → `menu_items` table te directly edit karo
Ya `categories` mein nawi category add karo

---

## Kharcha Summary

| | Cost |
|--|--|
| Meta WhatsApp API | ~₹0.40/conversation |
| Hostinger VPS | ₹300-500/month |
| Razorpay | 2% per transaction |
| **Total fixed** | **~₹400/month** |

---

## Problem aaye ta

1. `error_log` Hostinger te: File Manager → `logs/error_log`
2. WhatsApp message nahi aaya → Token check karo
3. Payment link nahi bani → Razorpay keys check karo
4. Webhook verify nahi hoya → VERIFY_TOKEN match karo

---

Made with ❤️ for Punjab da best restaurant! 🍛
