<?php
require_once '../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ============ PLACE ORDER ============
if ($method === 'POST' && $action === 'place') {
    $user = getAuthUser();
    if (!$user) respond(false, 'Please login to place an order.');

    $body       = getBody();
    $product_id = (int)($body['product_id'] ?? 0);
    $quantity   = (float)($body['quantity'] ?? 0);
    $payment    = $body['payment_method'] ?? 'cod';
    $txn_id     = $body['txn_id'] ?? null;
    $del        = $body['delivery'] ?? [];

    if (!$product_id || $quantity <= 0) {
        respond(false, 'Product and quantity are required.');
    }
    if (empty($del['name']) || empty($del['phone']) || empty($del['address'])) {
        respond(false, 'Complete delivery details are required.');
    }

    $db = getDB();

    // Get product
    $pstmt = $db->prepare("SELECT * FROM products WHERE id=? AND is_active=1 AND stock>=?");
    $pstmt->bind_param('id', $product_id, $quantity);
    $pstmt->execute();
    $product = $pstmt->get_result()->fetch_assoc();
    if (!$product) respond(false, 'Product not available or insufficient stock.');

    $unit_price   = $product['price'];
    $total_amount = $unit_price * $quantity;
    $delivery_fee = ($total_amount >= 1000) ? 0 : 60;
    $order_code   = 'ORD-' . strtoupper(substr(uniqid(), -6));

    // Begin transaction
    $db->begin_transaction();
    try {
        $stmt = $db->prepare("
            INSERT INTO orders
              (order_code, buyer_id, farmer_id, product_id, quantity,
               unit_price, total_amount, delivery_fee,
               payment_method, txn_id, payment_status,
               del_name, del_phone, del_address, del_district, del_note)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $pay_status  = ($payment === 'cod') ? 'pending' : 'paid';
        $del_name    = $del['name'];
        $del_phone   = $del['phone'];
        $del_address = $del['address'];
        $del_dist    = $del['district'] ?? '';
        $del_note    = $del['note'] ?? '';

        $stmt->bind_param(
            'siiiidddsssssss',
            $order_code, $user['id'], $product['farmer_id'], $product_id,
            $quantity, $unit_price, $total_amount, $delivery_fee,
            $payment, $txn_id, $pay_status,
            $del_name, $del_phone, $del_address, $del_dist, $del_note
        );
        // Fix bind_param count: 16 values
        $stmt = $db->prepare("
            INSERT INTO orders
              (order_code, buyer_id, farmer_id, product_id, quantity,
               unit_price, total_amount, delivery_fee,
               payment_method, txn_id, payment_status,
               del_name, del_phone, del_address, del_district, del_note)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->bind_param(
            'siiiidddssssssss',
            $order_code,
            $user['id'],
            $product['farmer_id'],
            $product_id,
            $quantity,
            $unit_price,
            $total_amount,
            $delivery_fee,
            $payment,
            $txn_id,
            $pay_status,
            $del_name,
            $del_phone,
            $del_address,
            $del_dist,
            $del_note
        );
        $stmt->execute();
        $order_id = $db->insert_id;

        // Reduce stock
        $upd = $db->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
        $upd->bind_param('di', $quantity, $product_id);
        $upd->execute();

        // Notify farmer
        $msg  = "New order {$order_code} for {$product['title']} × {$quantity}";
        $notif = $db->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?,?,?,'order')");
        $notif_title = 'New Order Received!';
        $notif->bind_param('iss', $product['farmer_id'], $notif_title, $msg);
        $notif->execute();

        $db->commit();
        respond(true, 'Order placed successfully!', [
            'order_id'   => $order_id,
            'order_code' => $order_code,
            'total'      => $total_amount + $delivery_fee
        ]);
    } catch (Exception $e) {
        $db->rollback();
        respond(false, 'Order failed: ' . $e->getMessage());
    }
}

// ============ MY ORDERS (buyer) ============
if ($method === 'GET' && $action === 'my_orders') {
    $user = getAuthUser();
    if (!$user) respond(false, 'Unauthorized.');

    $db   = getDB();
    $stmt = $db->prepare("
        SELECT o.*, p.title AS product_title, p.image,
               u.name AS farmer_name
        FROM orders o
        JOIN products p ON p.id = o.product_id
        JOIN users u ON u.id = o.farmer_id
        WHERE o.buyer_id = ?
        ORDER BY o.created_at DESC
    ");
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    respond(true, 'Orders loaded.', $rows);
}

// ============ FARMER ORDERS ============
if ($method === 'GET' && $action === 'farmer_orders') {
    $user = getAuthUser();
    if (!$user || $user['role'] !== 'farmer') respond(false, 'Unauthorized.');

    $db   = getDB();
    $status = $_GET['status'] ?? '';
    $where  = 'o.farmer_id = ?';
    if ($status) $where .= " AND o.status = '$status'";

    $stmt = $db->prepare("
        SELECT o.*, p.title AS product_title,
               u.name AS buyer_name, u.phone AS buyer_phone
        FROM orders o
        JOIN products p ON p.id = o.product_id
        JOIN users u ON u.id = o.buyer_id
        WHERE $where
        ORDER BY o.created_at DESC
    ");
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    respond(true, 'Orders loaded.', $rows);
}

// ============ UPDATE ORDER STATUS ============
if ($method === 'POST' && $action === 'update_status') {
    $user = getAuthUser();
    if (!$user) respond(false, 'Unauthorized.');

    $body      = getBody();
    $order_id  = (int)($body['order_id'] ?? 0);
    $new_status = $body['status'] ?? '';

    $allowed = ['confirmed','packed','shipped','delivered','cancelled'];
    if (!in_array($new_status, $allowed)) respond(false, 'Invalid status.');

    $db   = getDB();
    $check = $db->prepare("SELECT * FROM orders WHERE id=?");
    $check->bind_param('i', $order_id);
    $check->execute();
    $order = $check->get_result()->fetch_assoc();
    if (!$order) respond(false, 'Order not found.');

    // Farmers can update their orders; admin can update any
    if ($user['role'] !== 'admin' && $order['farmer_id'] != $user['id']) {
        respond(false, 'Permission denied.');
    }

    $stmt = $db->prepare("UPDATE orders SET status=? WHERE id=?");
    $stmt->bind_param('si', $new_status, $order_id);
    $stmt->execute();

    // Notify buyer
    $notif = $db->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?,?,?,'order')");
    $title = 'Order Status Updated';
    $msg   = "Your order {$order['order_code']} is now: " . strtoupper($new_status);
    $notif->bind_param('iss', $order['buyer_id'], $title, $msg);
    $notif->execute();

    respond(true, 'Order status updated to ' . $new_status);
}

// ============ TRACK ORDER ============
if ($method === 'GET' && $action === 'track') {
    $code = $_GET['code'] ?? '';
    if (!$code) respond(false, 'Order code required.');

    $db   = getDB();
    $stmt = $db->prepare("
        SELECT o.*, p.title AS product_title,
               uf.name AS farmer_name, ub.name AS buyer_name
        FROM orders o
        JOIN products p ON p.id = o.product_id
        JOIN users uf ON uf.id = o.farmer_id
        JOIN users ub ON ub.id = o.buyer_id
        WHERE o.order_code = ?
    ");
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    if (!$order) respond(false, 'Order not found.');

    respond(true, 'Order found.', $order);
}

respond(false, 'Invalid request.');