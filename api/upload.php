<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

function uploadError($status, $message) {
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') uploadError(405, 'Use POST to upload a PDF.');
if (!isset($_SESSION['user_id'])) uploadError(401, 'Please log in first.');
$file = $_FILES['pdf'] ?? null;
if (!$file || !is_array($file) || !isset($file['error']) || is_array($file['error'])) uploadError(400, 'Select a PDF file (maximum 20 MB).');
if ($file['error'] !== UPLOAD_ERR_OK) uploadError(400, 'PDF upload failed. Maximum file size is 20 MB.');
if ($file['size'] <= 0 || $file['size'] > 20 * 1024 * 1024) uploadError(413, 'Maximum PDF size is 20 MB.');
if (!is_uploaded_file($file['tmp_name'])) uploadError(400, 'Invalid upload.');
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
if ($mime !== 'application/pdf' || file_get_contents($file['tmp_name'], false, null, 0, 5) !== '%PDF-') {
    uploadError(400, 'Only PDF files are accepted.');
}
$directory = dirname(__DIR__) . '/pdf';
if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) uploadError(500, 'Could not create PDF storage.');
$name = bin2hex(random_bytes(16)) . '.pdf';
if (!@move_uploaded_file($file['tmp_name'], $directory . '/' . $name)) uploadError(500, 'Could not save PDF to disk.');
http_response_code(201);
echo json_encode(['url' => 'pdf/' . $name]);
