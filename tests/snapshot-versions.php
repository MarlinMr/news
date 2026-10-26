<?php
// Integration test against a disposable MariaDB server; never point this at production.
require_once __DIR__ . '/../api/article-cache.php';
if (!getenv('TEST_DB_HOST') || !getenv('TEST_DB_PASSWORD')) {
    fwrite(STDERR, "Set TEST_DB_HOST and TEST_DB_PASSWORD to a disposable MariaDB server.\n"); exit(1);
}
function versionCheck($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$dsn = 'mysql:host=' . getenv('TEST_DB_HOST') . ';charset=utf8mb4';
$root = new PDO($dsn, 'root', getenv('TEST_DB_PASSWORD'), $options);
$name = 'snapshot_test_' . bin2hex(random_bytes(4));
$root->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $pdo = new PDO($dsn . ';dbname=' . $name, 'root', getenv('TEST_DB_PASSWORD'), $options);
    $pdo->exec(file_get_contents(__DIR__ . '/../docker/schema.sql'));
    $pdo->exec("UPDATE articles SET title = 'Original title', summary = 'Original summary', url = 'https://example.com/story', published_at = '2026-10-01 12:00:00' WHERE id = 1");
    $original = ['text' => 'Short initial text.', 'captured_at' => '2026-10-01 12:00:00'];
    $firstKey = saveArticleSnapshot($pdo, 'article', 1, 'Original title', 'https://example.com/story', 'Original summary', '2026-10-01 12:00:00', $original);
    $first = $pdo->query('SELECT * FROM article_snapshots WHERE article_id = 1')->fetch();
    versionCheck((bool)preg_match('/^[a-f0-9]{32}$/D', $firstKey), 'Stable version keys are created');
    $unchanged = refreshArticleSnapshot($pdo, 'article', 1, fn($url) => ['text' => $original['text'], 'captured_at' => '2026-10-01 12:01:00']);
    versionCheck($unchanged['status'] === 'unchanged' && $unchanged['version_key'] === $firstKey, 'Unchanged text does not create a duplicate version');
    versionCheck($pdo->query('SELECT cache_checked_at FROM articles WHERE id = 1')->fetchColumn() === '2026-10-01 12:01:00', 'Unchanged checks update scheduling state');
    $pdo->exec("UPDATE articles SET title = 'Manual edit', summary = 'Manual summary' WHERE id = 1");
    $manualEditCheck = refreshArticleSnapshot($pdo, 'article', 1, fn($url) => ['text' => $original['text'], 'captured_at' => '2026-10-01 12:02:00']);
    versionCheck($manualEditCheck['status'] === 'unchanged', 'Missing publisher metadata does not turn map edits into fake capture changes');
    $bodyOnly = refreshArticleSnapshot($pdo, 'article', 1, fn($url) => ['text' => $original['text'] . "\n\nMore background details.", 'captured_at' => '2026-10-01 12:10:00']);
    versionCheck($bodyOnly['status'] === 'saved' && (int)$pdo->query('SELECT COUNT(*) FROM article_snapshots WHERE article_id = 1')->fetchColumn() === 2, 'Body expansion alone creates a new version even if headline and summary stay unchanged');
    $expanded = ['text' => "Short initial text.\n\nMuch more detail is now available.", 'captured_at' => '2026-10-01 12:15:00',
        'metadata' => ['title' => 'Expanded headline', 'description' => 'Expanded summary', 'published_at' => '2026-10-01T10:00:00Z']];
    $result = refreshArticleSnapshot($pdo, 'article', 1, fn($url) => $expanded);
    versionCheck($result['status'] === 'saved' && $result['version_key'] !== $firstKey, 'Expanded articles append a version');
    versionCheck((int)$pdo->query('SELECT COUNT(*) FROM article_snapshots WHERE article_id = 1')->fetchColumn() === 3, 'Original and expanded versions coexist');
    versionCheck($pdo->query('SELECT * FROM article_snapshots WHERE id = ' . (int)$first['id'])->fetch() === $first, 'Original capture remains immutable');
    versionCheck($pdo->query('SELECT title FROM articles WHERE id = 1')->fetchColumn() === 'Manual edit', 'Captures do not overwrite map article metadata');
    $again = refreshArticleSnapshot($pdo, 'article', 1, fn($url) => array_replace($expanded, ['captured_at' => '2026-10-01 12:16:00']));
    versionCheck($again['status'] === 'unchanged', 'Repeated checks of expanded text do not duplicate it');
    $failed = refreshArticleSnapshot($pdo, 'article', 1, fn($url) => ['text' => '', 'captured_at' => '2026-10-01 12:20:00']);
    versionCheck($failed['status'] === 'unavailable' && (int)$pdo->query('SELECT COUNT(*) FROM article_snapshots WHERE article_id = 1')->fetchColumn() === 3, 'Failed captures never replace readable versions or append empty ones');
    $reverted = refreshArticleSnapshot($pdo, 'article', 1, fn($url) => ['text' => $original['text'], 'captured_at' => '2026-10-01 12:25:00']);
    versionCheck($reverted['status'] === 'saved', 'A real reversion to earlier text is preserved as another capture');

    $pdo->exec("UPDATE article_updates SET title = 'Follow-up', url = 'https://example.com/update' WHERE id = 1");
    saveArticleSnapshot($pdo, 'update', 1, 'Follow-up', 'https://example.com/update', '', '2026-10-01 12:00:00', ['text' => '', 'captured_at' => '2026-10-01 12:00:00']);
    $followUp = refreshArticleSnapshot($pdo, 'update', 1, fn($url) => ['text' => 'Readable follow-up now available.', 'captured_at' => '2026-10-01 12:30:00']);
    versionCheck($followUp['status'] === 'saved' && (int)$pdo->query('SELECT COUNT(*) FROM article_snapshots WHERE update_id = 1')->fetchColumn() === 2, 'Follow-ups keep their own version histories');
    $legacy = refreshArticleSnapshot($pdo, 'article', 2, fn($url) => ['text' => 'First available capture for an old entry.', 'captured_at' => '2026-10-01 12:30:00']);
    versionCheck($legacy['status'] === 'saved', 'Old entries without captures can gain a first version');
    $pdo->exec("UPDATE articles SET url = 'pdf/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.pdf' WHERE id = 3");
    $pdf = refreshArticleSnapshot($pdo, 'article', 3, function() { throw new RuntimeException('PDFs must not be fetched'); });
    versionCheck($pdf['status'] === 'pdf', 'Local PDFs are not re-fetched');
    $before = $pdo->query('SELECT cache_checked_at FROM articles WHERE id = 1')->fetchColumn();
    $changed = refreshArticleSnapshot($pdo, 'article', 1, function($url) use ($pdo) {
        $pdo->exec("UPDATE articles SET url = 'https://example.com/changed' WHERE id = 1");
        return ['text' => 'Text from an obsolete URL', 'captured_at' => '2026-10-01 13:00:00'];
    });
    versionCheck($changed['status'] === 'source_changed' && $pdo->query('SELECT cache_checked_at FROM articles WHERE id = 1')->fetchColumn() === $before, 'Concurrent URL changes do not attach text to the wrong source');

    // Exercise the exact migration against the old unique-per-parent schema.
    $pdo->exec('DROP TABLE article_snapshots');
    $pdo->exec('ALTER TABLE articles DROP COLUMN cache_checked_at');
    $pdo->exec('ALTER TABLE article_updates DROP COLUMN cache_checked_at');
    $pdo->exec(file_get_contents(__DIR__ . '/../docker/article-cache.sql'));
    $pdo->exec("INSERT INTO article_snapshots (article_id,title,url,summary,published_at,article_text,captured_at) VALUES (1,'Old capture','https://example.com/old','','2026-10-01 12:00:00','Preserved old body','2026-10-01 12:00:00')");
    $pdo->exec("INSERT INTO article_snapshots (article_id,title,url,summary,published_at,article_text,captured_at) VALUES (2,'Old capture','pdf/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.pdf','','2026-10-01 12:00:00','Preserved old body','2026-10-01 12:00:00')");
    $pdo->exec(file_get_contents(__DIR__ . '/../docker/snapshot-versions.sql'));
    $migrated = $pdo->query('SELECT * FROM article_snapshots')->fetch();
    versionCheck($migrated['article_text'] === 'Preserved old body' && strlen($migrated['version_key']) === 32, 'Migration preserves old text and assigns a stable key');
    versionCheck((int)$pdo->query('SELECT COUNT(DISTINCT version_key) FROM article_snapshots')->fetchColumn() === 1, 'Migration keys do not depend on remapped parent IDs or PDF paths');
    saveArticleSnapshot($pdo, 'article', 1, 'New capture', 'https://example.com/new', '', '2026-10-01 12:00:00', ['text' => 'New text', 'captured_at' => '2026-10-01 13:00:00']);
    versionCheck((int)$pdo->query('SELECT COUNT(*) FROM article_snapshots WHERE article_id = 1')->fetchColumn() === 2, 'Migration replaces unique parent indexes without breaking foreign keys');
    $pdo->exec('DELETE FROM articles WHERE id = 1');
    versionCheck((int)$pdo->query('SELECT COUNT(*) FROM article_snapshots')->fetchColumn() === 1, 'Deleting one article preserves other histories');
    $pdo->exec('DELETE FROM articles WHERE id = 2');
    versionCheck((int)$pdo->query('SELECT COUNT(*) FROM article_snapshots')->fetchColumn() === 0, 'Deletion cascades to all versions');
    fwrite(STDOUT, "Snapshot version integration tests passed.\n");
} finally { $root->exec("DROP DATABASE IF EXISTS `$name`"); }
