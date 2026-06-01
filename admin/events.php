<?php
// ============================================
//  REAL-TIME SSE — admin/events.php
//  Server-Sent Events: browser nu real-time
//  new order push karda — koi reload nahi
// ============================================

session_start();
if (!($_SESSION['admin'] ?? false)) {
    http_response_code(403); exit;
}

require_once __DIR__ . '/../config/config.php';

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');   // nginx buffering off
header('Access-Control-Allow-Origin: *');

// Disable output buffering
if (ob_get_level()) ob_end_clean();
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);

$lastId  = (int)($_GET['lastId'] ?? 0);
$maxTime = 50;   // reconnect every 50s (before server timeout)
$start   = time();

// Tell browser to reconnect after 3s if connection drops
echo "retry: 3000\n\n";
flush();

while (!connection_aborted() && (time() - $start) < $maxTime) {

    try {
        $db   = getDB();

        // ---- New orders check ----
        $stmt = $db->prepare(
            "SELECT id, order_number, customer_name, phone, customer_phone,
                    total, items, delivery_address, payment_method, payment_status,
                    created_at
             FROM orders WHERE id > ? ORDER BY id ASC LIMIT 5"
        );
        $stmt->execute([$lastId]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($orders)) {
            foreach ($orders as $order) {
                echo "event: new_order\n";
                echo "data: " . json_encode($order) . "\n\n";
                $lastId = max($lastId, (int)$order['id']);
            }
            flush();
        }

        // ---- Live stats (every tick) ----
        $stats = $db->query(
            "SELECT
                COUNT(*) as today_orders,
                COALESCE(SUM(CASE WHEN order_status IN ('waiting','confirmed','preparing') THEN 1 ELSE 0 END),0) as pending,
                COALESCE(SUM(CASE WHEN DATE(created_at)=CURDATE() THEN total ELSE 0 END),0) as today_rev,
                COALESCE(SUM(total),0) as total_rev
             FROM orders WHERE DATE(created_at)=CURDATE() OR order_status IN ('waiting','confirmed','preparing')"
        )->fetch(PDO::FETCH_ASSOC);

        echo "event: stats\n";
        echo "data: " . json_encode($stats) . "\n\n";
        flush();

    } catch (Exception $e) {
        // DB error — send heartbeat and continue
        echo ": db_error\n\n";
        flush();
    }

    sleep(3);
}

// Send last seen ID so browser reconnects from right place
echo "event: reconnect\n";
echo "data: " . json_encode(['lastId' => $lastId]) . "\n\n";
flush();
