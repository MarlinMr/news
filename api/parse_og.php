<?php
require_once __DIR__ . '/metadata.php';
header('Content-Type: application/json; charset=utf-8');
try {
    echo json_encode(fetchArticleMetadata($_GET['url'] ?? ''), JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage()]);
}
