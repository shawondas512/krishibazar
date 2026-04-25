<?php
require_once __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

// ============ GET ALL PRICES ============
if ($method === 'GET' && $action === 'list') {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT * FROM market_prices
        ORDER BY price_date DESC, crop_name ASC
    ");
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    respond(true, 'Prices loaded.', $rows);
}

// ============ UPDATE PRICE (admin only) ============
if ($method === 'POST' && $action === 'update') {
    $user = getAuthUser();
    if (!$user || $user['role'] !== 'admin') respond(false, 'Admin only.');

    $body = getBody();
    $db   = getDB();

    foreach ($body['prices'] ?? [] as $p) {
        $stmt = $db->prepare("
            INSERT INTO market_prices
              (crop_name, category, min_price, max_price, avg_price, change_pct, trend, source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              min_price=VALUES(min_price), max_price=VALUES(max_price),
              avg_price=VALUES(avg_price), change_pct=VALUES(change_pct),
              trend=VALUES(trend), price_date=CURDATE()
        ");
        $stmt->bind_param('ssddddss',
            $p['crop_name'], $p['category'],
            $p['min_price'], $p['max_price'], $p['avg_price'],
            $p['change_pct'], $p['trend'], $p['source']
        );
        $stmt->execute();
    }
    respond(true, 'Prices updated!');
}

respond(false, 'Invalid request.');