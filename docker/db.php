<?php
// Environment-based PDO connection installed as api/db.php by the Docker build.
$host = getenv('MARIADB_HOST') ?: getenv('DB_HOST') ?: 'db';
$port = getenv('MARIADB_PORT') ?: getenv('DB_PORT') ?: '3306';
$db   = getenv('MARIADB_DATABASE') ?: getenv('DB_NAME') ?: 'geonews';
$user = getenv('MARIADB_USER') ?: getenv('DB_USER') ?: 'geonews_user';
$pass = getenv('MARIADB_PASSWORD') ?: getenv('DB_PASS') ?: 'geonews_password';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    error_log("DB Connection failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database connection error']);
    exit;
}
