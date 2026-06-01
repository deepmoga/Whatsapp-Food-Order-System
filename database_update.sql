-- ============================================
--  DATABASE UPDATE — New Features
--  Run this in phpMyAdmin SQL tab
-- ============================================

USE food_bot;

-- ============================================
--  1. RESTAURANT SETTINGS TABLE
--     Delivery charges, service area, Google review link
-- ============================================
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT DEFAULT NULL,
    description VARCHAR(255) DEFAULT '',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Default settings
INSERT INTO settings (setting_key, setting_value, description) VALUES
('restaurant_name',       'Mera Dhaba',          'Restaurant naam'),
('restaurant_phone',      '919XXXXXXXXX',        'Restaurant WhatsApp number'),
('restaurant_address',    'Moga, Punjab',        'Restaurant address'),
('restaurant_lat',        '30.8145',             'Restaurant latitude'),
('restaurant_lng',        '75.1683',             'Restaurant longitude'),
('service_radius_km',     '5',                   'Service radius in KM'),
('google_review_link',    '',                    'Google Review link'),
('delivery_charge',       '50',                  'Default delivery charge (Rs)'),
('free_delivery_above',   '500',                 'Free delivery above this amount (0 = always charge)'),
('min_order_amount',      '100',                 'Minimum order amount'),
('cod_enabled',           '1',                   'Cash on Delivery enabled (1=yes, 0=no)'),
('online_payment_enabled','1',                   'Online payment enabled (1=yes, 0=no)'),
('currency',              'INR',                 'Currency code'),
('estimated_time',        '30-45',               'Estimated delivery time in minutes'),
('review_after_minutes',  '60',                  'Send review link after X minutes of delivery')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- ============================================
--  2. COUPONS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS coupons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    type ENUM('flat','percent') DEFAULT 'flat',
    value DECIMAL(10,2) NOT NULL,
    min_order DECIMAL(10,2) DEFAULT 0,
    max_discount DECIMAL(10,2) DEFAULT 0,
    usage_limit INT DEFAULT 0,
    used_count INT DEFAULT 0,
    per_user_limit INT DEFAULT 1,
    is_active TINYINT(1) DEFAULT 1,
    expires_at DATE DEFAULT NULL,
    description VARCHAR(255) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Sample coupons
INSERT INTO coupons (code, type, value, min_order, max_discount, usage_limit, description, expires_at) VALUES
('WELCOME50',  'flat',    50,  200, 0,  100, 'New customer - ₹50 off',       DATE_ADD(CURDATE(), INTERVAL 30 DAY)),
('SAVE10',     'percent', 10,  300, 80, 200, '10% off max ₹80',              DATE_ADD(CURDATE(), INTERVAL 60 DAY)),
('FLAT100',    'flat',    100, 500, 0,  50,  '₹100 off on orders above ₹500', DATE_ADD(CURDATE(), INTERVAL 15 DAY))
ON DUPLICATE KEY UPDATE code = code;

-- ============================================
--  3. COUPON USAGE TRACKING
-- ============================================
CREATE TABLE IF NOT EXISTS coupon_usage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    coupon_id INT NOT NULL,
    phone VARCHAR(20) NOT NULL,
    order_id INT NOT NULL,
    discount_amount DECIMAL(10,2) NOT NULL,
    used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (coupon_id) REFERENCES coupons(id)
);

-- ============================================
--  4. UPDATE ORDERS TABLE — New columns
-- ============================================
ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS coupon_code VARCHAR(30) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(10,2) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS delivery_charge DECIMAL(10,2) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS payment_method ENUM('online','cod') DEFAULT 'online',
    ADD COLUMN IF NOT EXISTS customer_lat DECIMAL(10,8) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS customer_lng DECIMAL(11,8) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS distance_km DECIMAL(5,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS review_sent TINYINT(1) DEFAULT 0;

-- ============================================
--  5. UPDATE SESSIONS TABLE — Pending states
-- ============================================
ALTER TABLE sessions
    ADD COLUMN IF NOT EXISTS pending_item_id INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS pending_coupon VARCHAR(30) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS pending_discount DECIMAL(10,2) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS delivery_charge DECIMAL(10,2) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS payment_method VARCHAR(10) DEFAULT 'online';


-- ============================================
--  PATCH 2 — Customer phone + status messages
-- ============================================

-- Add customer_phone to orders
ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS customer_phone VARCHAR(20) DEFAULT NULL;

-- Add customer_phone to sessions
ALTER TABLE sessions
    ADD COLUMN IF NOT EXISTS customer_phone VARCHAR(20) DEFAULT NULL;

-- Status message templates
CREATE TABLE IF NOT EXISTS status_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    status VARCHAR(30) NOT NULL UNIQUE,
    message TEXT NOT NULL,
    is_active TINYINT(1) DEFAULT 1
);

INSERT INTO status_messages (status, message, is_active) VALUES
('confirmed',  '✅ *Order #{order_number} confirm ho gaya!*\n\n{items}\n\n💰 Total: ₹{total}\n⏱ {estimated_time} minutes mein milega\n\nShukriya {name} ji! 🙏', 1),
('preparing',  '👨‍🍳 *Order #{order_number} taiyaar ho raha hai!*\n\nRasoi mein kaam shuru ho gaya. Thoda intezaar karo ji 😊', 1),
('ready',      '🎉 *Order #{order_number} ready hai!*\n\n{delivery_or_pickup}', 1),
('delivered',  '✅ *Order #{order_number} deliver ho gaya!*\n\nKhane da mazaa lao ji! 🍽\n\nThoda time kadke Google review zaroor dena:\n{review_link}', 1),
('cancelled',  '❌ *Order #{order_number} cancel ho gaya.*\n\nKoi problem hai ta call karo: +{restaurant_phone}', 1)
ON DUPLICATE KEY UPDATE status = status;

-- ============================================
--  PATCH 3 — API Keys in DB + GST
-- ============================================

-- Add new settings
INSERT INTO settings (setting_key, setting_value, description) VALUES
('whatsapp_token',         '',          'Meta WhatsApp Access Token'),
('whatsapp_phone_id',      '',          'WhatsApp Phone Number ID'),
('verify_token',           'fb_verify_1a3d2222353f3f7fd0f32ba4a877378bd0c191b5', 'Webhook Verify Token'),
('razorpay_key_id',        '',          'Razorpay Key ID'),
('razorpay_key_secret',    '',          'Razorpay Key Secret'),
('razorpay_webhook_secret','',          'Razorpay Webhook Secret'),
('base_url',               '',          'Website base URL e.g. https://yourdomain.com/food-bot'),
('gst_enabled',            '0',         'GST enabled (1=yes, 0=no)'),
('gst_percent',            '5',         'GST percentage'),
('gst_included',           '0',         'GST included in price (1) or added on top (0)')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- ============================================
--  PATCH 4 — Bill token for shareable links
-- ============================================
ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS bill_token VARCHAR(64) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS bill_viewed_at TIMESTAMP NULL DEFAULT NULL;

-- Generate tokens for existing orders
UPDATE orders SET bill_token = SHA2(CONCAT(id, order_number, created_at), 256) WHERE bill_token IS NULL;

-- ============================================
--  PATCH 4 — Bill token for public bill view
-- ============================================
ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS bill_token VARCHAR(64) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS bill_viewed_at TIMESTAMP NULL DEFAULT NULL;

-- Index for fast token lookup
CREATE INDEX IF NOT EXISTS idx_bill_token ON orders(bill_token);

-- Add restaurant logo/tagline to settings
INSERT INTO settings (setting_key, setting_value, description) VALUES
('restaurant_logo_url', '',     'Restaurant logo image URL'),
('restaurant_tagline',  '',     'Tagline shown on bill'),
('restaurant_gstin',    '',     'GST Registration Number (optional)'),
('bill_footer_text',    'Shukriya! Dobara aana ji 🙏', 'Bill footer message')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- ============================================
--  PATCH 5 — Store Hours & On/Off
-- ============================================

-- Store schedule per day
CREATE TABLE IF NOT EXISTS store_schedule (
    id INT AUTO_INCREMENT PRIMARY KEY,
    day_of_week TINYINT NOT NULL COMMENT '0=Sunday, 1=Monday ... 6=Saturday',
    day_name VARCHAR(15) NOT NULL,
    is_open TINYINT(1) DEFAULT 1,
    open_time TIME DEFAULT '10:00:00',
    close_time TIME DEFAULT '22:00:00'
);

-- Insert 7 days default
INSERT IGNORE INTO store_schedule (day_of_week, day_name, is_open, open_time, close_time) VALUES
(0, 'Sunday',    1, '10:00:00', '22:00:00'),
(1, 'Monday',    1, '10:00:00', '22:00:00'),
(2, 'Tuesday',   1, '10:00:00', '22:00:00'),
(3, 'Wednesday', 1, '10:00:00', '22:00:00'),
(4, 'Thursday',  1, '10:00:00', '22:00:00'),
(5, 'Friday',    1, '10:00:00', '22:00:00'),
(6, 'Saturday',  1, '10:00:00', '22:00:00');

-- Store on/off + closed message settings
INSERT INTO settings (setting_key, setting_value, description) VALUES
('store_open',          '1',   'Manual store toggle (1=open, 0=closed)'),
('store_closed_message','Maafi karo ji, abhi restaurant band hai.\n\nHumara time:\nSomvar-Shanivaar: 10:00 AM - 10:00 PM\nItvaar: 11:00 AM - 9:00 PM\n\nKhulne te aapko message karenge!', 'Message when store is closed'),
('store_busy_message',  '', 'Optional: Extra busy message (leave blank to hide)')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- ============================================
--  PATCH 5 — Store Hours + On/Off
-- ============================================

-- Store timing per day
CREATE TABLE IF NOT EXISTS store_hours (
    id INT AUTO_INCREMENT PRIMARY KEY,
    day_of_week TINYINT NOT NULL COMMENT '0=Sunday, 1=Monday ... 6=Saturday',
    day_name VARCHAR(10) NOT NULL,
    is_open TINYINT(1) DEFAULT 1,
    open_time TIME DEFAULT '09:00:00',
    close_time TIME DEFAULT '22:00:00'
);

-- Insert all 7 days
INSERT IGNORE INTO store_hours (day_of_week, day_name, is_open, open_time, close_time) VALUES
(0, 'Sunday',    1, '09:00:00', '22:00:00'),
(1, 'Monday',    1, '09:00:00', '22:00:00'),
(2, 'Tuesday',   1, '09:00:00', '22:00:00'),
(3, 'Wednesday', 1, '09:00:00', '22:00:00'),
(4, 'Thursday',  1, '09:00:00', '22:00:00'),
(5, 'Friday',    1, '09:00:00', '22:00:00'),
(6, 'Saturday',  1, '09:00:00', '22:00:00');

-- Store on/off setting
INSERT INTO settings (setting_key, setting_value, description) VALUES
('store_open',         '1',                         'Store manually on(1) ya off(0)'),
('store_closed_msg',   'Sorry ji, abhi restaurant band hai.\n\nHumari timings:\n{timings}\n\nKal zaroor aana!', 'Closed hone te message'),
('store_timezone',     'Asia/Kolkata',               'Timezone for store hours')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- ============================================
--  PATCH 6 — Item Add-ons / Toppings
-- ============================================

-- Add-ons table (e.g. Extra Cheese, Toppings for pizza)
CREATE TABLE IF NOT EXISTS item_addons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    price DECIMAL(10,2) DEFAULT 0.00,
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    FOREIGN KEY (item_id) REFERENCES menu_items(id) ON DELETE CASCADE
);

-- Add pending_addons column to sessions
ALTER TABLE sessions
    ADD COLUMN IF NOT EXISTS pending_addons TEXT DEFAULT NULL;

-- ============================================
--  PATCH 7 — Delivery Boy System
-- ============================================

CREATE TABLE IF NOT EXISTS delivery_boys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    whatsapp_number VARCHAR(20) NOT NULL COMMENT 'Without + e.g. 919XXXXXXXXX',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS delivery_boy_id INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS delivery_assigned_at TIMESTAMP NULL DEFAULT NULL;
