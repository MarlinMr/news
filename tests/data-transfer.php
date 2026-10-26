<?php
// Integration test: creates and drops two disposable databases on a TEST MariaDB server.
// TEST_DB_HOST and TEST_DB_PASSWORD must point to a dedicated test server with CREATE DATABASE privileges.
require_once __DIR__ . '/../api/data-transfer-lib.php';
date_default_timezone_set('Europe/Oslo');
if (!getenv('TEST_DB_HOST') || !getenv('TEST_DB_PASSWORD')) {
    fwrite(STDERR, "Set TEST_DB_HOST and TEST_DB_PASSWORD to a disposable MariaDB server.\n");
    exit(1);
}
function transferCheck($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
function transferFails($callback, $message) {
    try { $callback(); } catch (Throwable $e) { return; }
    throw new RuntimeException($message);
}
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$dsn = 'mysql:host=' . getenv('TEST_DB_HOST') . ';charset=utf8mb4';
$root = new PDO($dsn, 'root', getenv('TEST_DB_PASSWORD'), $options);
$prefix = 'transfer_test_' . bin2hex(random_bytes(4));
$directory = sys_get_temp_dir() . '/' . $prefix;
mkdir($directory);
mkdir($directory . '/source');
mkdir($directory . '/destination');
try {
    $connections = [];
    foreach (['source', 'destination'] as $name) {
        $db = $prefix . '_' . $name;
        $root->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $connections[$name] = new PDO($dsn . ';dbname=' . $db, 'root', getenv('TEST_DB_PASSWORD'), $options);
        $connections[$name]->exec(file_get_contents(__DIR__ . '/../docker/schema.sql'));
    }
    ['source' => $source, 'destination' => $destination] = $connections;
    $source->exec("UPDATE articles SET published_at = '2026-10-02 00:00:00'");
    $source->exec("UPDATE articles SET published_at = '2026-10-01 00:00:00' WHERE id = 1");
    $source->exec("UPDATE articles SET published_at = '2026-10-01 23:59:59' WHERE id = 3");
    $source->exec("UPDATE article_updates SET published_at = '2026-11-10 15:00:00'");
    $pdf = 'pdf/' . str_repeat('a', 32) . '.pdf';
    $pdfContent = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
    file_put_contents($directory . '/source/' . basename($pdf), $pdfContent);
    $source->prepare('UPDATE articles SET url = ? WHERE id = 1')->execute([$pdf]);
    $source->prepare('UPDATE article_updates SET url = ? WHERE id = 1')->execute([$pdf]);
    $source->exec("INSERT INTO article_comments (article_id, user_id, body, published_at) VALUES (1, 2, 'Comment outside date range', '2026-11-10 15:00:00')");
    $stmt = $source->prepare("INSERT INTO article_snapshots (article_id, update_id, title, url, summary, published_at, article_text, captured_at, version_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, REPLACE(UUID(), '-', ''))");
    $stmt->execute([1, null, 'Original Ålesund title', $pdf, 'Original summary', '2026-10-01 00:00:00', 'Saved article text <script>text only</script>', '2026-10-01 00:01:00']);
    $stmt->execute([null, 1, 'Original follow-up', $pdf, '', '2026-11-10 15:00:00', 'Saved follow-up text', '2026-11-10 15:01:00']);

    $archive = $directory . '/export.zip';
    transferCheck(transferExport($source, '2026-10-01', '2026-10-01', $directory . '/source', $archive) === 2, 'Date range includes both day boundaries');
    [$zip, $data, $names] = transferReadArchive($archive);
    $zip->close();
    transferCheck(count($data['updates']) === 2 && count($data['comments']) === 1 && count($data['snapshots']) === 2 && isset($names[$pdf]), 'Include follow-ups outside range, comments, snapshots and PDFs');
    transferCheck(array_keys($data['users'][0]) === ['id', 'username'], 'No credentials, emails or roles exported');

    $result = transferImport($destination, $archive, $directory . '/destination');
    transferCheck($result['articles'] === 2 && $result['updates'] === 2 && $result['comments'] === 1 && $result['snapshots'] === 2 && $result['pdfs'] === 1, 'Round trip all records');
    $mappedId = (int)$destination->query("SELECT local_id FROM data_transfer_records WHERE record_type = 'articles' AND source_id = 1")->fetchColumn();
    transferCheck($mappedId > 3, 'Remap conflicting database IDs');
    $url = $destination->query("SELECT url FROM articles WHERE id = $mappedId")->fetchColumn();
    transferCheck($url !== $pdf && file_get_contents($directory . '/destination/' . basename($url)) === $pdfContent, 'Remap and preserve PDF bytes');
    $snapshot = $destination->query("SELECT * FROM article_snapshots WHERE article_id = $mappedId")->fetch();
    transferCheck($snapshot['url'] === $url && $snapshot['title'] === 'Original Ålesund title' && $snapshot['article_text'] === 'Saved article text <script>text only</script>', 'Preserve immutable snapshot metadata and text');
    transferCheck((int)$destination->query("SELECT COUNT(*) FROM article_updates WHERE article_id = $mappedId")->fetchColumn() === 2, 'Remap follow-up parent IDs');
    transferCheck((int)$destination->query("SELECT COUNT(*) FROM users WHERE email LIKE '%@offline.invalid' AND role = 'user'")->fetchColumn() === count($data['users']), 'Imported authors have no source privileges');
    $destination->exec("UPDATE articles SET title = 'Local edit' WHERE id = $mappedId");
    $repeat = transferImport($destination, $archive, $directory . '/destination');
    transferCheck($repeat['articles'] === 0 && $repeat['updates'] === 0 && $repeat['pdfs'] === 0 && $repeat['skipped'] === 5, 'Repeat imports do not duplicate records or PDFs');
    transferCheck($destination->query("SELECT title FROM articles WHERE id = $mappedId")->fetchColumn() === 'Local edit', 'Keep local edits');

    $source->exec("INSERT INTO article_snapshots (article_id, version_key, title, url, summary, published_at, article_text, captured_at) VALUES (1, REPLACE(UUID(), '-', ''), 'Expanded title', '$pdf', 'Expanded summary', '2026-10-01 00:00:00', 'Much longer later article text', '2026-10-01 00:15:00')");
    transferExport($source, '2026-10-01', '2026-10-01', $directory . '/source', $archive);
    $expanded = transferImport($destination, $archive, $directory . '/destination');
    transferCheck($expanded['articles'] === 0 && $expanded['snapshots'] === 1, 'Import new snapshot versions for previously imported articles');
    transferCheck((int)$destination->query("SELECT COUNT(*) FROM article_snapshots WHERE article_id = $mappedId")->fetchColumn() === 2, 'Keep original and expanded versions');
    transferCheck($destination->query("SELECT title FROM articles WHERE id = $mappedId")->fetchColumn() === 'Local edit', 'New captures do not replace local article edits');
    $expandedAgain = transferImport($destination, $archive, $directory . '/destination');
    transferCheck($expandedAgain['snapshots'] === 0, 'Repeated imports do not duplicate snapshot versions');
    $source->exec("INSERT INTO article_snapshots (article_id, version_key, title, url, summary, published_at, article_text, captured_at) VALUES (1, REPLACE(UUID(), '-', ''), 'Original Ålesund title', '$pdf', 'Original summary', '2026-10-01 00:00:00', 'Saved article text <script>text only</script>', '2026-10-01 00:01:00')");
    $source->exec("INSERT INTO article_snapshots (update_id, version_key, title, url, summary, published_at, article_text, captured_at) VALUES (1, REPLACE(UUID(), '-', ''), 'Expanded follow-up', '$pdf', '', '2026-11-10 15:00:00', 'Longer follow-up text', '2026-11-10 16:00:00')");
    transferExport($source, '2026-10-01', '2026-10-01', $directory . '/source', $archive);
    $moreVersions = transferImport($destination, $archive, $directory . '/destination');
    transferCheck($moreVersions['snapshots'] === 2, 'Preserve distinct version keys with identical timestamps/content and append follow-up versions');
    transferCheck((int)$destination->query("SELECT COUNT(*) FROM article_snapshots WHERE article_id = $mappedId")->fetchColumn() === 3, 'Do not collapse genuine same-timestamp captures');

    $legacyData = $data; $legacyData['version'] = 1;
    foreach ($legacyData['snapshots'] as &$legacySnapshot) unset($legacySnapshot['version_key']);
    unset($legacySnapshot);
    foreach (['articles', 'updates'] as $group) foreach ($legacyData[$group] as &$legacyRow) unset($legacyRow['cache_checked_at']);
    unset($legacyRow);
    $legacyArchive = $directory . '/legacy.zip';
    $legacyZip = new ZipArchive(); $legacyZip->open($legacyArchive, ZipArchive::CREATE);
    $legacyZip->addFromString('data.json', json_encode($legacyData)); $legacyZip->addFromString($pdf, $pdfContent); $legacyZip->close();
    $legacyImport = transferImport($destination, $legacyArchive, $directory . '/destination');
    transferCheck($legacyImport['snapshots'] === 0, 'Legacy archives deduplicate against migrated snapshot histories');

    $source->exec("INSERT INTO article_updates (article_id, user_id, title, url, source_name, published_at) VALUES (1, 1, 'New follow-up', 'https://example.com/follow-up', 'Example', '2026-12-01 12:00:00')");
    transferExport($source, '2026-10-01', '2026-10-01', $directory . '/source', $archive);
    $overlap = transferImport($destination, $archive, $directory . '/destination');
    transferCheck($overlap['articles'] === 0 && $overlap['updates'] === 1, 'Overlapping imports add new follow-ups');

    $source->exec("INSERT INTO articles (user_id, title, url, latitude, longitude, published_at) VALUES (1, 'Unlocated story', 'https://example.com/inbox', NULL, NULL, '2026-10-01 15:00:00')");
    $sourceInboxId = (int)$source->lastInsertId();
    $source->exec("INSERT INTO article_snapshots (article_id, version_key, title, url, summary, published_at, article_text, captured_at) VALUES ($sourceInboxId, REPLACE(UUID(), '-', ''), 'Original inbox title', 'https://example.com/inbox', 'Original summary', '2026-10-01 15:00:00', 'Original inbox text', '2026-10-01 15:01:00')");
    transferExport($source, '2026-10-01', '2026-10-01', $directory . '/source', $archive);
    [$inboxZip, $inboxData] = transferReadArchive($archive); $inboxZip->close();
    transferCheck($inboxData['version'] === 3, 'Export versioned snapshot archive format');
    $inboxResult = transferImport($destination, $archive, $directory . '/destination');
    transferCheck($inboxResult['articles'] === 1, 'Import unlocated stories');
    $inboxRow = $destination->query("SELECT id, latitude, longitude FROM articles WHERE url = 'https://example.com/inbox'")->fetch();
    transferCheck($inboxRow && $inboxRow['latitude'] === null && $inboxRow['longitude'] === null, 'Preserve null inbox coordinates');
    $destinationInboxId = (int)$inboxRow['id'];
    $inboxSnapshotBefore = $destination->query("SELECT * FROM article_snapshots WHERE article_id = $destinationInboxId")->fetch();
    $stillUnlocated = transferImport($destination, $archive, $directory . '/destination');
    transferCheck($stillUnlocated['located'] === 0, 'Unlocated source does not assign coordinates');
    $destination->exec("UPDATE articles SET title = 'Local inbox title' WHERE id = $destinationInboxId");
    $source->exec("UPDATE articles SET latitude = 58.1, longitude = 7.2, title = 'Changed source title' WHERE id = $sourceInboxId");
    transferExport($source, '2026-10-01', '2026-10-01', $directory . '/source', $archive);
    $placed = transferImport($destination, $archive, $directory . '/destination');
    transferCheck($placed['articles'] === 0 && $placed['located'] === 1, 'Repeat import places an existing inbox story instead of duplicating it');
    $placedRow = $destination->query("SELECT * FROM articles WHERE id = $destinationInboxId")->fetch();
    transferCheck((float)$placedRow['latitude'] === 58.1 && (float)$placedRow['longitude'] === 7.2 && $placedRow['title'] === 'Local inbox title', 'Copy only missing coordinates and preserve local edits');
    transferCheck($destination->query("SELECT * FROM article_snapshots WHERE article_id = $destinationInboxId")->fetch() === $inboxSnapshotBefore, 'Placement preserves original cached text and metadata');
    $destination->exec("UPDATE articles SET latitude = 60.1, longitude = 10.2 WHERE id = $destinationInboxId");
    $source->exec("UPDATE articles SET latitude = 57.5, longitude = 8 WHERE id = $sourceInboxId");
    transferExport($source, '2026-10-01', '2026-10-01', $directory . '/source', $archive);
    $keepLocal = transferImport($destination, $archive, $directory . '/destination');
    $localCoordinates = $destination->query("SELECT latitude, longitude FROM articles WHERE id = $destinationInboxId")->fetch();
    transferCheck($keepLocal['located'] === 0 && (float)$localCoordinates['latitude'] === 60.1 && (float)$localCoordinates['longitude'] === 10.2, 'Never overwrite an existing local placement');
    $source->exec("UPDATE articles SET latitude = NULL, longitude = NULL WHERE id = $sourceInboxId");
    transferExport($source, '2026-10-01', '2026-10-01', $directory . '/source', $archive);
    transferImport($destination, $archive, $directory . '/destination');
    transferCheck($destination->query("SELECT latitude, longitude FROM articles WHERE id = $destinationInboxId")->fetch() === $localCoordinates, 'An older unlocated source does not clear destination coordinates');
    $destination->exec("UPDATE articles SET latitude = NULL, longitude = NULL WHERE id = $destinationInboxId");
    $source->exec("UPDATE articles SET latitude = 0, longitude = 0 WHERE id = $sourceInboxId");
    transferExport($source, '2026-10-01', '2026-10-01', $directory . '/source', $archive);
    $zeroPlacement = transferImport($destination, $archive, $directory . '/destination');
    $zeroRow = $destination->query("SELECT latitude, longitude FROM articles WHERE id = $destinationInboxId")->fetch();
    transferCheck($zeroPlacement['located'] === 1 && (float)$zeroRow['latitude'] === 0.0 && (float)$zeroRow['longitude'] === 0.0, 'Zero coordinates are valid placements');
    $legacy = $data; $legacy['version'] = 1;
    transferValidate($legacy);
    $mixed = $inboxData;
    $mixed['articles'][count($mixed['articles']) - 1]['longitude'] = 10;
    transferFails(fn() => transferValidate($mixed), 'Reject incomplete coordinate pairs');
    transferFails(fn() => transferImport($source, $archive, $directory . '/destination'), 'Reject self-import');
    transferFails(fn() => transferExport($source, '2026-10-02', '2026-10-01', $directory . '/source', $archive), 'Reject reversed dates');
    transferFails(fn() => transferExport($source, '2026-02-30', '2026-10-01', $directory . '/source', $archive), 'Reject invalid calendar dates');
    transferFails(fn() => transferExport($source, '2026-10-01', '2026-10-01', $directory . '/missing', $directory . '/missing.zip'), 'Reject missing PDF on export');

    // A late database error must roll back rows and clean up the PDFs already copied.
    $brokenData = $data;
    $brokenData['origin'] = str_repeat('b', 32);
    $duplicateEmail = $brokenData['origin'] . '-' . $brokenData['users'][0]['id'] . '@offline.invalid';
    $destination->prepare("INSERT INTO users (username, email, password_hash, role) VALUES ('collision', ?, 'unused', 'user')")->execute([$duplicateEmail]);
    $broken = $directory . '/broken.zip';
    $zip = new ZipArchive(); $zip->open($broken, ZipArchive::CREATE);
    $zip->addFromString('data.json', json_encode($brokenData)); $zip->addFromString($pdf, $pdfContent); $zip->close();
    $before = $destination->query('SELECT COUNT(*) FROM articles')->fetchColumn();
    $filesBefore = glob($directory . '/destination/*.pdf');
    transferFails(fn() => transferImport($destination, $broken, $directory . '/destination'), 'Fail on database error');
    transferCheck($destination->query('SELECT COUNT(*) FROM articles')->fetchColumn() === $before && glob($directory . '/destination/*.pdf') === $filesBefore, 'Rollback database and PDF writes');

    $zip = new ZipArchive(); $zip->open($broken, ZipArchive::OVERWRITE);
    $zip->addFromString('data.json', json_encode($data)); $zip->addFromString('../escape.php', '<?php'); $zip->close();
    transferFails(fn() => transferReadArchive($broken), 'Reject archive path traversal');
    $zip = new ZipArchive(); $zip->open($broken, ZipArchive::OVERWRITE);
    $zip->addFromString('data.json', json_encode($data)); $zip->close();
    transferFails(fn() => transferImport($destination, $broken, $directory . '/destination'), 'Reject missing PDF on import');
    $invalid = $data; $invalid['updates'][0]['article_id'] = 999999;
    transferFails(fn() => transferValidate($invalid), 'Reject missing parent records');
    $invalid = $data; $invalid['articles'][0]['url'] = 'javascript:alert(1)';
    transferFails(fn() => transferValidate($invalid), 'Reject executable URLs');

    // Existing installations can apply the migration without recreating their data.
    $destination->exec('DROP TABLE data_transfer_records, data_transfer_origin');
    $destination->exec(file_get_contents(__DIR__ . '/../docker/data-transfer.sql'));
    transferCheck($destination->query('SELECT COUNT(*) FROM articles')->fetchColumn() === $before, 'Migration preserves articles');
    fwrite(STDOUT, "Data transfer integration tests passed.\n");
} finally {
    foreach (['source', 'destination'] as $name) $root->exec('DROP DATABASE IF EXISTS `' . $prefix . '_' . $name . '`');
    foreach (glob($directory . '/*/*') as $file) unlink($file);
    foreach (glob($directory . '/*') as $file) { if (is_dir($file)) rmdir($file); else unlink($file); }
    rmdir($directory);
}
