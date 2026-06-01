-- WhatsApp Food Ordering System
-- Run this in your Hostinger MySQL panel (phpMyAdmin)

CREATE DATABASE IF NOT EXISTS food_bot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE food_bot;

-- Menu Categories
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    emoji VARCHAR(10) DEFAULT '',
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1
);

-- Menu Items
CREATE TABLE menu_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(255) DEFAULT '',
    price DECIMAL(10,2) NOT NULL,
    is_available TINYINT(1) DEFAULT 1,
    FOREIGN KEY (category_id) REFERENCES categories(id)
);

-- Customer Sessions (conversation state tracker)
CREATE TABLE sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(20) NOT NULL UNIQUE,
    customer_name VARCHAR(100) DEFAULT '',
    state VARCHAR(50) DEFAULT 'WELCOME',
    cart JSON DEFAULT '[]',
    selected_category INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Orders
CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(20) NOT NULL UNIQUE,
    phone VARCHAR(20) NOT NULL,
    customer_name VARCHAR(100) DEFAULT '',
    items JSON NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    payment_status ENUM('pending','paid','failed','refunded') DEFAULT 'pending',
    order_status ENUM('waiting','confirmed','preparing','ready','delivered','cancelled') DEFAULT 'waiting',
    razorpay_order_id VARCHAR(100) DEFAULT NULL,
    razorpay_payment_id VARCHAR(100) DEFAULT NULL,
    payment_link VARCHAR(500) DEFAULT NULL,
    delivery_address TEXT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Message Logs (for debugging)
CREATE TABLE message_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(20),
    direction ENUM('in','out') NOT NULL,
    message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =====================
-- SAMPLE MENU DATA
-- =====================

INSERT INTO categories (name, emoji, sort_order) VALUES
('Starters', '🍢', 1),
('Main Course Veg', '🥗', 2),
('Main Course Non-Veg', '🍗', 3),
('Breads & Rice', '🍞', 4),
('Drinks & Desserts', '🍹', 5);

INSERT INTO menu_items (category_id, name, price) VALUES
-- Starters
(1, 'Paneer Tikka', 180.00),
(1, 'Chicken Tikka', 220.00),
(1, 'Veg Pakora', 120.00),
(1, 'Samosa (2 pcs)', 60.00),
-- Main Veg
(2, 'Dal Makhani', 160.00),
(2, 'Palak Paneer', 180.00),
(2, 'Shahi Paneer', 200.00),
(2, 'Mix Veg', 150.00),
-- Main Non-Veg
(3, 'Butter Chicken', 250.00),
(3, 'Mutton Curry', 320.00),
(3, 'Chicken Karahi', 260.00),
-- Breads & Rice
(4, 'Butter Naan', 40.00),
(4, 'Tandoori Roti', 30.00),
(4, 'Jeera Rice', 130.00),
(4, 'Plain Rice', 100.00),
-- Drinks
(5, 'Sweet Lassi', 80.00),
(5, 'Salted Lassi', 80.00),
(5, 'Mango Shake', 100.00),
(5, 'Gulab Jamun (2 pcs)', 70.00);
