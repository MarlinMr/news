<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();

// Centralized DB Connection
require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? '';

// ------------------------------------------------------------------
// 1. CHECK CURRENT SESSION STATUS
// ------------------------------------------------------------------
if ($action === 'me') {
    if (isset($_SESSION['user_id'])) {
        echo json_encode([
            'authenticated' => true,
            'user' => [
                'id'       => $_SESSION['user_id'],
                'username' => $_SESSION['username'],
                'email'    => $_SESSION['email'],
                'role'     => $_SESSION['user_role']
            ]
        ]);
    } else {
        echo json_encode(['authenticated' => false]);
    }
    exit;
}

// ------------------------------------------------------------------
// 2. USER REGISTRATION
// ------------------------------------------------------------------
if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $username = trim($input['username'] ?? '');
    $email    = filter_var(trim($input['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $password = $input['password'] ?? '';

    if (empty($username) || !$email || strlen($password) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'Valid username, email, and password (min 6 chars) required.']);
        exit;
    }

    // Check if user already exists
    $checkStmt = $pdo->prepare("SELECT id FROM users WHERE username = :username OR email = :email");
    $checkStmt->execute(['username' => $username, 'email' => $email]);
    if ($checkStmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Username or email already taken.']);
        exit;
    }

    // Hash password and save user
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    $insertStmt = $pdo->prepare("
        INSERT INTO users (username, email, password_hash, role) 
        VALUES (:username, :email, :hash, 'user')
    ");

    if ($insertStmt->execute(['username' => $username, 'email' => $email, 'hash' => $passwordHash])) {
        $newUserId = $pdo->lastInsertId();

        // Auto login on successful registration
        $_SESSION['user_id']   = $newUserId;
        $_SESSION['username']  = $username;
        $_SESSION['email']     = $email;
        $_SESSION['user_role'] = 'user';

        echo json_encode(['message' => 'User registered successfully', 'user' => [
            'id' => $newUserId, 'username' => $username, 'role' => 'user'
        ]]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create user account.']);
    }
    exit;
}

// ------------------------------------------------------------------
// 3. USER LOGIN
// ------------------------------------------------------------------
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $loginInput = trim($input['username_or_email'] ?? '');
    $password   = $input['password'] ?? '';

    if (empty($loginInput) || empty($password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Please provide username/email and password.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :input OR email = :input");
    $stmt->execute(['input' => $loginInput]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['username']  = $user['username'];
        $_SESSION['email']     = $user['email'];
        $_SESSION['user_role'] = $user['role'];

        echo json_encode(['message' => 'Login successful', 'user' => [
            'id' => $user['id'], 'username' => $user['username'], 'role' => $user['role']
        ]]);
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid credentials.']);
    }
    exit;
}

// ------------------------------------------------------------------
// 4. USER LOGOUT
// ------------------------------------------------------------------
if ($action === 'logout') {
    session_unset();
    session_destroy();
    echo json_encode(['message' => 'Logged out successfully']);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid auth endpoint action.']);
