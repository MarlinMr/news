<?php
date_default_timezone_set('Europe/Oslo');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
session_start();

function transferReply($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION['user_id'])) transferReply(['error' => 'Please sign in on the map first.'], 401);
if (($_SESSION['user_role'] ?? '') !== 'admin') transferReply(['error' => 'Admin access required.'], 403);
$_SESSION['transfer_token'] = $_SESSION['transfer_token'] ?? bin2hex(random_bytes(32));
$token = $_SESSION['transfer_token'];
session_write_close();
if (!class_exists('ZipArchive')) transferReply(['error' => 'Enable the PHP zip extension on this server to use export/import.'], 503);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/data-transfer-lib.php';
$action = $_GET['action'] ?? 'info';
$method = $_SERVER['REQUEST_METHOD'];
$temporary = null;
try {
    if ($method === 'GET' && $action === 'info') {
        // Fail early with installation guidance if a migration has not been applied.
        $pdo->query('SELECT origin FROM data_transfer_origin LIMIT 0');
        $pdo->query('SELECT source_id FROM data_transfer_records LIMIT 0');
        $pdo->query('SELECT id FROM article_snapshots LIMIT 0');
        $range = $pdo->query('SELECT DATE(MIN(published_at)) AS start_date, DATE(MAX(published_at)) AS end_date FROM articles')->fetch();
        transferReply(['token' => $token, 'range' => $range, 'max_archive_bytes' => TRANSFER_MAX_ARCHIVE]);
    }
    @set_time_limit(300);
    if ($method === 'GET' && $action === 'export') {
        $temporary = tempnam(sys_get_temp_dir(), 'geonews-export-');
        if (!$temporary) throw new RuntimeException('Could not create temporary archive.');
        $start = $_GET['start_date'] ?? '';
        $end = $_GET['end_date'] ?? '';
        transferExport($pdo, $start, $end, dirname(__DIR__) . '/pdf', $temporary);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="geonews-' . $start . '-to-' . $end . '.zip"');
        header('Content-Length: ' . filesize($temporary));
        header('X-Content-Type-Options: nosniff');
        readfile($temporary);
        unlink($temporary);
        exit;
    }
    if ($method === 'POST' && $action === 'import') {
        if (!hash_equals($token, $_SERVER['HTTP_X_TRANSFER_TOKEN'] ?? '')) transferReply(['error' => 'Session expired. Reload this page and try again.'], 403);
        $file = $_FILES['archive'] ?? null;
        if (!$file || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) throw new InvalidArgumentException('Archive upload failed. Maximum size is 128 MB; check the server upload limits.');
        if (!is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('Invalid archive upload.');
        $counts = transferImport($pdo, $file['tmp_name'], dirname(__DIR__) . '/pdf');
        transferReply(['message' => 'Import complete.', 'counts' => $counts]);
    }
    transferReply(['error' => 'Unsupported action or method.'], 405);
} catch (Throwable $e) {
    if ($temporary && is_file($temporary)) unlink($temporary);
    if ($e instanceof InvalidArgumentException || $e instanceof JsonException) transferReply(['error' => $e->getMessage()], 400);
    error_log('Data transfer: ' . $e->getMessage());
    $message = $e instanceof PDOException && $e->getCode() === '42S02'
        ? 'Database tables are missing. Apply docker/article-cache.sql and docker/data-transfer.sql first.'
        : ($e instanceof RuntimeException && !($e instanceof PDOException) ? $e->getMessage() : 'Could not transfer data. Check the server log. No database changes were committed.');
    transferReply(['error' => $message], 500);
}
