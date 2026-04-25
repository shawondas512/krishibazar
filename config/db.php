<?php
// ============================================
// KrishiBazar — Database Configuration
// ============================================

define('DB_HOST', 'localhost');
define('DB_USER', 'root');       // XAMPP default
define('DB_PASS', '');           // XAMPP default (empty password)
define('DB_NAME', 'krishibazar');

function getDB() {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            http_response_code(500);
            die(json_encode([
                'success' => false,
                'message' => 'Database connection failed: ' . $conn->connect_error
            ]));
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

// CORS headers — allow frontend to call API
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Helper: send JSON response
function respond($success, $message = '', $data = []) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data
    ]);
    exit();
}

// Helper: get request body as array
function getBody() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

// Helper: get auth user from Bearer token
function getAuthUser() {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (!$auth || !str_starts_with($auth, 'Bearer ')) return null;

    $token = substr($auth, 7);
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE token = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}