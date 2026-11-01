<?php
require_once __DIR__ . '/metadata.php';
header('Content-Type: application/json; charset=utf-8');
// Pure text analysis: no article writes, external fetches, or archive/schema changes.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Use POST with a title and summary.']);
    exit;
}
try {
    $raw = file_get_contents('php://input', false, null, 0, 20001);
    if (strlen($raw) > 20000) throw new InvalidArgumentException('Text is too long.');
    $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($input) || !is_string($input['title'] ?? null) || !is_string($input['summary'] ?? '')) {
        throw new InvalidArgumentException('Expected a title and optional summary.');
    }
    echo json_encode(['location_suggestions' => guessPlaceCandidates($input['title'], $input['summary'] ?? '')], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException | JsonException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
