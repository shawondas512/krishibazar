<?php
require_once '../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ============ REGISTER ============
if ($method === 'POST' && $action === 'register') {
    $body = getBody();

    $name     = trim($body['name'] ?? '');
    $phone    = trim($body['phone'] ?? '');
    $password = $body['password'] ?? '';
    $role     = $body['role'] ?? 'consumer';
    $district = $body['district'] ?? '';

    if (!$name || !$phone || !$password) {
        respond(false, 'Name, phone, and password are required.');
    }

    if (strlen($phone) !== 11 || !str_starts_with($phone, '01')) {
        respond(false, 'Enter a valid 11-digit Bangladeshi mobile number.');
    }

    if (strlen($password) < 6) {
        respond(false, 'Password must be at least 6 characters.');
    }

    $allowed_roles = ['farmer', 'retailer', 'consumer'];
    if (!in_array($role, $allowed_roles)) $role = 'consumer';

    $db = getDB();

    // Check duplicate
    $check = $db->prepare("SELECT id FROM users WHERE phone = ?");
    $check->bind_param('s', $phone);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        respond(false, 'This phone number is already registered.');
    }

    $hashed   = password_hash($password, PASSWORD_DEFAULT);
    $token    = bin2hex(random_bytes(32));

    $stmt = $db->prepare("
        INSERT INTO users (name, phone, password, role, district, token, is_verified)
        VALUES (?, ?, ?, ?, ?, ?, 1)
    ");
    $stmt->bind_param('ssssss', $name, $phone, $hashed, $role, $district, $token);

    if ($stmt->execute()) {
        $user_id = $db->insert_id;
        respond(true, 'Registration successful!', [
            'id'       => $user_id,
            'name'     => $name,
            'phone'    => $phone,
            'role'     => $role,
            'district' => $district,
            'token'    => $token
        ]);
    } else {
        respond(false, 'Registration failed. Please try again.');
    }
}

// ============ LOGIN ============
if ($method === 'POST' && $action === 'login') {
    $body     = getBody();
    $phone    = trim($body['phone'] ?? '');
    $password = $body['password'] ?? '';

    if (!$phone || !$password) {
        respond(false, 'Phone and password are required.');
    }

    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE phone = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param('s', $phone);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user || !password_verify($password, $user['password'])) {
        respond(false, 'Invalid phone number or password.');
    }

    // Refresh token
    $token = bin2hex(random_bytes(32));
    $db->prepare("UPDATE users SET token = ? WHERE id = ?")->execute() ;
    $upd = $db->prepare("UPDATE users SET token = ? WHERE id = ?");
    $upd->bind_param('si', $token, $user['id']);
    $upd->execute();

    respond(true, 'Login successful!', [
        'id'       => $user['id'],
        'name'     => $user['name'],
        'phone'    => $user['phone'],
        'role'     => $user['role'],
        'district' => $user['district'],
        'token'    => $token
    ]);
}

// ============ GET PROFILE ============
if ($method === 'GET' && $action === 'profile') {
    $user = getAuthUser();
    if (!$user) respond(false, 'Unauthorized. Please login.');

    unset($user['password'], $user['token']);
    respond(true, 'Profile loaded.', $user);
}

// ============ UPDATE PROFILE ============
if ($method === 'POST' && $action === 'update_profile') {
    $user = getAuthUser();
    if (!$user) respond(false, 'Unauthorized.');

    $body     = getBody();
    $name     = trim($body['name'] ?? $user['name']);
    $district = $body['district'] ?? $user['district'];

    $db   = getDB();
    $stmt = $db->prepare("UPDATE users SET name=?, district=? WHERE id=?");
    $stmt->bind_param('ssi', $name, $district, $user['id']);
    $stmt->execute();

    respond(true, 'Profile updated successfully!');
}

respond(false, 'Invalid request.');