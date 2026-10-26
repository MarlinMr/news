<?php
require_once __DIR__ . '/../api/metadata.php';

$cases = [
    ['12 personer er såret', 12, null],
    ['Tre personer ble såret', 3, null],
    ['En person er såret', 1, null],
    ['5 sårede og 2 drepte', 5, 2],
    ['To personar er såra', 2, null],
    ['4 PERSONER ER SÅRET', 4, null],
    ['Three injured and two killed', 3, 2],
    ['To personer er skadde', 2, null],
    ['Flere personer er såret', null, null],
    ['Ingen såret', null, null],
];
foreach ($cases as [$text, $injured, $killed]) {
    $result = extractCasualties($text);
    if ($result['injured'] !== $injured || $result['killed'] !== $killed) {
        throw new RuntimeException('Unexpected casualty counts for: ' . $text);
    }
}
$metadata = parseArticleMetadata('<html><head><title>Tre såret i en hendelse</title><meta name="description" content="To personer er drept."></head><body></body></html>');
if ($metadata['injured'] !== 3 || $metadata['killed'] !== 2) {
    throw new RuntimeException('Metadata parsing must include såret in casualty extraction.');
}
fwrite(STDOUT, "Metadata casualty tests passed.\n");
