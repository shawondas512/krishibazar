<?php
require_once '../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

// ============ LIST PRODUCTS ============
if ($method === 'GET' && $action === 'list') {
    $db = getDB();

    $where  = ['p.is_active = 1'];
    $params = [];
    $types  = '';

    if (!empty($_GET['category'])) {
        $where[]  = 'p.category = ?';
        $params[] = $_GET['category'];
        $types   .= 's';
    }
    if (!empty($_GET['search'])) {
        $where[]  = '(p.title LIKE ? OR p.location LIKE ? OR u.name LIKE ?)';
        $s        = '%' . $_GET['search'] . '%';
        $params   = array_merge($params, [$s, $s, $s]);
        $types   .= 'sss';
    }
    if (!empty($_GET['min_price'])) {
        $where[]  = 'p.price >= ?';
        $params[] = (float)$_GET['min_price'];
        $types   .= 'd';
    }
    if (!empty($_GET['max_price'])) {
        $where[]  = 'p.price <= ?';
        $params[] = (float)$_GET['max_price'];
        $types   .= 'd';
    }

    $whereSQL = implode(' AND ', $where);
    $sort = match($_GET['sort'] ?? '') {
        'price_low'  => 'p.price ASC',
        'price_high' => 'p.price DESC',
        'rating'     => 'avg_rating DESC',
        default      => 'p.created_at DESC'
    };

    $sql = "
        SELECT p.*,
               u.name AS farmer_name, u.district AS farmer_district,
               COALESCE(AVG(r.rating), 0) AS avg_rating,
               COUNT(r.id) AS review_count
        FROM products p
        JOIN users u ON u.id = p.farmer_id
        LEFT JOIN reviews r ON r.product_id = p.id
        WHERE $whereSQL
        GROUP BY p.id
        ORDER BY $sort
        LIMIT 100
    ";

    $stmt = $db->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    respond(true, 'Products loaded.', $rows);
}

// ============ SINGLE PRODUCT ============
if ($method === 'GET' && $action === 'single') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(false, 'Product ID required.');

    $db   = getDB();
    $stmt = $db->prepare("
        SELECT p.*, u.name AS farmer_name, u.district AS farmer_district,
               u.phone AS farmer_phone,
               COALESCE(AVG(r.rating), 0) AS avg_rating,
               COUNT(r.id) AS review_count
        FROM products p
        JOIN users u ON u.id = p.farmer_id
        LEFT JOIN reviews r ON r.product_id = p.id
        WHERE p.id = ? AND p.is_active = 1
        GROUP BY p.id
    ");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    if (!$product) respond(false, 'Product not found.');

    // Get reviews
    $rev = $db->prepare("
        SELECT r.*, u.name AS buyer_name
        FROM reviews r
        JOIN users u ON u.id = r.buyer_id
        WHERE r.product_id = ?
        ORDER BY r.created_at DESC
        LIMIT 20
    ");
    $rev->bind_param('i', $id);
    $rev->execute();
    $product['reviews'] = $rev->get_result()->fetch_all(MYSQLI_ASSOC);

    respond(true, 'Product loaded.', $product);
}

// ============ ADD PRODUCT (farmer only) ============
if ($method === 'POST' && $action === 'add') {
    $user = getAuthUser();
    if (!$user) respond(false, 'Unauthorized.');
    if ($user['role'] !== 'farmer') respond(false, 'Only farmers can add products.');

    $body  = getBody();
    $title = trim($body['title'] ?? '');
    $price = (float)($body['price'] ?? 0);
    $stock = (int)($body['stock'] ?? 0);

    if (!$title || $price <= 0) respond(false, 'Title and price are required.');

    $db    = getDB();
    $cat   = $body['category'] ?? 'other';
    $unit  = $body['unit'] ?? 'kg';
    $desc  = $body['description'] ?? '';
    $loc   = $body['location'] ?? $user['district'];
    $image = $body['image'] ?? null;

    $stmt = $db->prepare("
        INSERT INTO products (farmer_id, title, category, price, unit, stock,
                              description, location, image)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('issdsisss', $user['id'], $title, $cat, $price,
                      $unit, $stock, $desc, $loc, $image);

    if ($stmt->execute()) {
        respond(true, 'Product listed successfully!', ['id' => $db->insert_id]);
    } else {
        respond(false, 'Failed to add product.');
    }
}

// ============ UPDATE PRODUCT ============
if ($method === 'POST' && $action === 'update') {
    $user = getAuthUser();
    if (!$user) respond(false, 'Unauthorized.');

    $body  = getBody();
    $id    = (int)($body['id'] ?? 0);
    $db    = getDB();

    // Verify ownership
    $check = $db->prepare("SELECT farmer_id FROM products WHERE id=?");
    $check->bind_param('i', $id);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    if (!$row || ($row['farmer_id'] != $user['id'] && $user['role'] !== 'admin')) {
        respond(false, 'Permission denied.');
    }

    $price = (float)($body['price'] ?? 0);
    $stock = (int)($body['stock'] ?? 0);
    $title = trim($body['title'] ?? '');

    $stmt = $db->prepare("UPDATE products SET title=?, price=?, stock=? WHERE id=?");
    $stmt->bind_param('sdii', $title, $price, $stock, $id);
    $stmt->execute();

    respond(true, 'Product updated!');
}

// ============ DELETE PRODUCT ============
if ($method === 'POST' && $action === 'delete') {
    $user = getAuthUser();
    if (!$user) respond(false, 'Unauthorized.');

    $body = getBody();
    $id   = (int)($body['id'] ?? 0);
    $db   = getDB();

    $check = $db->prepare("SELECT farmer_id FROM products WHERE id=?");
    $check->bind_param('i', $id);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    if (!$row || ($row['farmer_id'] != $user['id'] && $user['role'] !== 'admin')) {
        respond(false, 'Permission denied.');
    }

    $stmt = $db->prepare("UPDATE products SET is_active=0 WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();

    respond(true, 'Product removed from listing.');
}

// ============ ADD REVIEW ============
if ($method === 'POST' && $action === 'review') {
    $user = getAuthUser();
    if (!$user) respond(false, 'Please login to leave a review.');

    $body       = getBody();
    $product_id = (int)($body['product_id'] ?? 0);
    $rating     = (int)($body['rating'] ?? 0);
    $comment    = trim($body['comment'] ?? '');

    if (!$product_id || $rating < 1 || $rating > 5) {
        respond(false, 'Valid product ID and rating (1-5) required.');
    }

    $db   = getDB();
    $stmt = $db->prepare("
        INSERT INTO reviews (product_id, buyer_id, rating, comment)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE rating=VALUES(rating), comment=VALUES(comment)
    ");
    $stmt->bind_param('iiis', $product_id, $user['id'], $rating, $comment);
    $stmt->execute();

    respond(true, 'Review submitted! Thank you.');
}

respond(false, 'Invalid request.');