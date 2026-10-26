<?php
// Uses only disposable databases on the dedicated TEST_DB_HOST server.
require_once __DIR__ . '/../api/data-transfer-lib.php';
require_once __DIR__ . '/../api/article-cache.php';
date_default_timezone_set('Europe/Oslo');
if (!getenv('TEST_DB_HOST') || !getenv('TEST_DB_PASSWORD')) exit("Set TEST_DB_HOST and TEST_DB_PASSWORD.\n");
function conversionCheck($value, $message) { if (!$value) throw new RuntimeException($message); }
function conversionAttempt($pdo, $source, $parent, $user = null, $role = 'user') {
    $pdo->beginTransaction();
    try { $id = convertArticleToUpdate($pdo, $source, $parent, $user, $role); $pdo->commit(); return $id; }
    catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}
function conversionRejects($callback, $message) {
    try { $callback(); } catch (LogicException $e) { return; }
    throw new RuntimeException($message);
}
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$dsn = 'mysql:host=' . getenv('TEST_DB_HOST') . ';charset=utf8mb4';
$root = new PDO($dsn, 'root', getenv('TEST_DB_PASSWORD'), $options);
$prefix = 'conversion_' . bin2hex(random_bytes(4));
$directory = sys_get_temp_dir() . '/' . $prefix;
mkdir($directory);
try {
    foreach (['source', 'destination', 'fresh', 'divergent'] as $name) {
        $root->exec("CREATE DATABASE `{$prefix}_$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $$name = new PDO($dsn . ";dbname={$prefix}_$name", 'root', getenv('TEST_DB_PASSWORD'), $options);
        $$name->exec(file_get_contents(__DIR__ . '/../docker/schema.sql'));
    }
    $source->exec("UPDATE articles SET published_at = '2026-09-01 12:00:00'");
    $source->exec("UPDATE articles SET published_at = '2026-10-01 12:00:00', latitude = NULL, longitude = NULL WHERE id = 1");
    $source->exec("INSERT INTO article_comments (article_id, user_id, body) VALUES (1, 2, 'Keep my comment')");
    $source->exec("INSERT INTO article_snapshots (article_id, version_key, title, url, summary, published_at, article_text, captured_at) VALUES
        (1, REPEAT('a',32), 'First title', 'https://example.com/1', 'First summary', '2026-10-01 12:00:00', 'Original text', '2026-10-01 12:01:00'),
        (1, REPEAT('b',32), 'Later title', 'https://example.com/1', 'Later summary', '2026-10-01 12:00:00', 'Expanded text', '2026-10-01 12:02:00')");
    $old = $directory . '/old.zip'; $new = $directory . '/new.zip';
    transferExport($source, '2026-09-01', '2026-10-01', $directory, $old);
    transferImport($destination, $old, $directory);
    $mapped = fn($type, $id) => (int)$destination->query("SELECT local_id FROM data_transfer_records WHERE record_type = '$type' AND source_id = $id")->fetchColumn();
    $localSource = $mapped('articles', 1); $localParent = $mapped('articles', 3);
    conversionRejects(fn() => conversionAttempt($source, 1, 1, 3), 'Reject self-conversion');
    conversionRejects(fn() => conversionAttempt($source, 3, 1, 3), 'Reject moving another author\'s located story');
    $update = conversionAttempt($source, 1, 3, 3); // Any signed-in user can place an inbox article.
    conversionCheck(articleDestination($source, 1) === ['article_id' => 3, 'update_id' => $update], 'Old article ID resolves to parent/update');
    conversionCheck((int)$source->query("SELECT COUNT(*) FROM article_snapshots WHERE update_id = $update")->fetchColumn() === 2, 'Preserve every cached version');
    conversionCheck((int)$source->query('SELECT COUNT(*) FROM article_updates WHERE article_id = 3')->fetchColumn() === 3, 'Move nested follow-ups');
    conversionCheck((int)$source->query('SELECT article_id FROM article_comments LIMIT 1')->fetchColumn() === 3, 'Move comments');
    conversionCheck((int)$source->query("SELECT user_id FROM article_updates WHERE id = $update")->fetchColumn() === 1, 'Preserve original author');
    conversionRejects(fn() => conversionAttempt($source, 1, 2, 3), 'Reject repeat conversion');
    conversionRejects(fn() => conversionAttempt($source, 3, 1, 1), 'Reject converted parent/cycle');
    // Local conversion before the source's new export must not duplicate its update.
    $localUpdate = conversionAttempt($destination, $localSource, $localParent);
    transferImport($destination, $old, $directory);
    conversionCheck((int)$destination->query("SELECT COUNT(*) FROM article_snapshots WHERE update_id = $localUpdate")->fetchColumn() === 2, 'Old imports preserve moved versions without duplicates');
    conversionCheck((int)$destination->query("SELECT COUNT(*) FROM article_updates WHERE article_id = $localSource")->fetchColumn() === 0, 'Old imports do not strand follow-ups');
    // Date selection of the child includes its older parent and redirect identity.
    transferExport($source, '2026-10-01', '2026-10-01', $directory, $new);
    [$zip, $data] = transferReadArchive($new); $zip->close();
    conversionCheck(count($data['articles']) === 2 && count($data['updates']) === 3, 'Export includes complete incident outside parent date range');
    $invalid = $data;
    $invalid['articles'][0]['converted_update_id'] = 999999;
    conversionRejects(fn() => transferValidate($invalid), 'Reject missing conversion update');
    transferImport($divergent, $old, $directory);
    $divergentIds = [];
    foreach ($divergent->query("SELECT source_id, local_id FROM data_transfer_records WHERE record_type = 'articles'")->fetchAll() as $identity) $divergentIds[$identity['source_id']] = $identity['local_id'];
    conversionAttempt($divergent, $divergentIds[3], $divergentIds[2]);
    transferImport($divergent, $new, $directory);
    conversionCheck(articleDestination($divergent, $divergentIds[1])['article_id'] === (int)$divergentIds[2], 'Source conversion follows a locally moved parent');
    transferImport($destination, $new, $directory);
    conversionCheck((int)$destination->query("SELECT COUNT(*) FROM article_updates WHERE article_id = $localParent")->fetchColumn() === 3, 'Independent local/source conversions merge update identity');
    transferImport($fresh, $old, $directory);
    transferImport($fresh, $new, $directory);
    $freshSource = (int)$fresh->query("SELECT local_id FROM data_transfer_records WHERE record_type = 'articles' AND source_id = 1")->fetchColumn();
    $freshTarget = articleDestination($fresh, $freshSource);
    conversionCheck($freshTarget['update_id'] !== null, 'Import conversion into previously imported standalone article');
    transferImport($fresh, $old, $directory);
    transferImport($fresh, $new, $directory);
    conversionCheck((int)$fresh->query('SELECT COUNT(*) FROM article_snapshots')->fetchColumn() === 2, 'Repeated old/new transfers retain exactly two versions');
    // Moving the parent again keeps redirects from its earlier children valid.
    $refresh = refreshArticleSnapshot($source, 'article', 3, function () use ($source) {
        conversionAttempt($source, 3, 2, 1);
        return ['text' => 'Late network capture', 'captured_at' => '2026-10-01 14:00:00', 'metadata' => []];
    });
    conversionCheck($refresh['status'] === 'source_changed', 'Concurrent cache refresh cannot strand a snapshot under the old article');
    conversionCheck(articleDestination($source, 1)['article_id'] === 2, 'Chained conversions resolve directly to current parent');
    transferExport($source, '2026-10-01', '2026-10-01', $directory, $new);
    transferImport($fresh, $new, $directory);
    conversionCheck(articleDestination($fresh, $freshSource)['article_id'] !== $freshTarget['article_id'], 'Transfers preserve chained conversions');
    // Deleting the moved update must leave a tombstone, never a new standalone pin.
    $source->exec("DELETE FROM article_updates WHERE id = $update");
    conversionCheck(articleDestination($source, 1) === false, 'Deleted update cannot resurrect its original article');
    // Manual migration preserves existing data.
    $source->exec('ALTER TABLE articles DROP INDEX article_converted_update, DROP COLUMN converted_update_id');
    $source->exec(file_get_contents(__DIR__ . '/../docker/article-conversion.sql'));
    conversionCheck((int)$source->query('SELECT COUNT(*) FROM articles')->fetchColumn() === 3, 'Migration keeps article records');
    fwrite(STDOUT, "Article conversion integration tests passed.\n");
} finally {
    foreach (['source', 'destination', 'fresh', 'divergent'] as $name) $root->exec("DROP DATABASE IF EXISTS `{$prefix}_$name`");
    foreach (glob($directory . '/*') as $file) unlink($file);
    rmdir($directory);
}
