<?php
date_default_timezone_set('Europe/Oslo');
header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/api/db.php';

function cacheEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function cacheSourceUrl($url) {
    if (preg_match('#^/?pdf/[a-f0-9]{32}\.pdf$#D', $url)) return ltrim($url, '/');
    if (filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)) return $url;
    return '';
}
function renderCachedText($row) {
    if ($row['captured_at']) {
        echo '<p class="meta">Snapshot captured ' . cacheEscape($row['captured_at']) . ' (Europe/Oslo).</p>';
    }
    if (!empty($row['article_text'])) {
        echo '<div class="article-text">' . cacheEscape($row['article_text']) . '</div>';
    } else {
        echo '<p class="notice">' . ($row['captured_at']
            ? 'Article text could not be captured when this entry was saved. The source may require JavaScript, a subscription, or block automated access. PDF uploads remain available through the original link.'
            : 'No snapshot is available. This entry predates article text caching.') . '</p>';
        if (!empty($row['summary'])) echo '<h3>Saved summary</h3><div class="article-text">' . cacheEscape($row['summary']) . '</div>';
    }
}
$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
$article = false;
$updates = [];
if ($id && $id > 0) {
    $stmt = $pdo->prepare('SELECT a.id, COALESCE(s.title, a.title) AS title, COALESCE(s.url, a.url) AS url, COALESCE(s.summary, a.summary) AS summary, COALESCE(s.published_at, a.published_at) AS published_at, s.article_text, s.captured_at FROM articles a LEFT JOIN article_snapshots s ON s.article_id = a.id WHERE a.id = ?');
    $stmt->execute([$id]);
    $article = $stmt->fetch();
    if ($article) {
        $stmt = $pdo->prepare('SELECT au.id, au.source_name, COALESCE(s.title, au.title) AS title, COALESCE(s.url, au.url) AS url, COALESCE(s.published_at, au.published_at) AS published_at, s.summary, s.article_text, s.captured_at FROM article_updates au LEFT JOIN article_snapshots s ON s.update_id = au.id WHERE au.article_id = ? ORDER BY published_at ASC, au.id ASC');
        $stmt->execute([$id]);
        $updates = $stmt->fetchAll();
    }
}
if (!$article) http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= cacheEscape($article ? $article['title'] . ' — Cached article' : 'Article not found') ?></title>
    <style>
        body { margin: 0; background: #f1f5f9; color: #0f172a; font: 17px/1.7 system-ui, sans-serif; }
        main { max-width: 800px; margin: 0 auto; padding: 24px 20px 60px; }
        article, section { background: white; border: 1px solid #e2e8f0; border-radius: 10px; padding: 28px; margin-top: 24px; }
        h1, h2, h3 { line-height: 1.3; overflow-wrap: anywhere; }
        h1 { font-size: 2rem; } h2 { font-size: 1.5rem; }
        a { color: #0369a1; overflow-wrap: anywhere; }
        .meta { color: #64748b; font-size: .9rem; }
        .article-text { white-space: pre-wrap; overflow-wrap: anywhere; }
        .notice { background: #f8fafc; border-left: 3px solid #94a3b8; padding: 12px; }
        li { margin-bottom: 8px; }
        :target { outline: 2px solid #0284c7; outline-offset: 4px; }
        @media (max-width: 600px) { article, section { padding: 18px; } }
    </style>
</head>
<body>
<main>
    <nav><a href="./<?= $article ? '?id=' . (int)$article['id'] : '' ?>">← Back to map</a></nav>
    <?php if (!$article): ?>
        <h1>Article not found</h1>
        <p>This article may have been deleted, or the link is invalid.</p>
    <?php else: ?>
        <p class="meta">Cached article · Text saved when each entry was added. Publisher changes are not reflected here. Captures may be partial.</p>
        <article id="main-article">
            <h1><?= cacheEscape($article['title']) ?></h1>
            <p class="meta">Published <?= cacheEscape($article['published_at']) ?> (Europe/Oslo)</p>
            <?php if ($sourceUrl = cacheSourceUrl($article['url'])): ?>
                <p><a href="<?= cacheEscape($sourceUrl) ?>" target="_blank" rel="noopener noreferrer">Read original article →</a></p>
            <?php endif; ?>
            <?php renderCachedText($article); ?>
        </article>
        <h2 id="follow-ups">Follow-up articles (<?= count($updates) ?>)</h2>
        <?php if (!$updates): ?>
            <p>No follow-up articles have been added yet.</p>
        <?php else: ?>
            <nav aria-label="Follow-up articles"><ol>
                <?php foreach ($updates as $update): ?>
                    <li><a href="#update-<?= (int)$update['id'] ?>"><?= cacheEscape($update['title']) ?></a></li>
                <?php endforeach; ?>
            </ol></nav>
            <?php foreach ($updates as $update): ?>
                <section id="update-<?= (int)$update['id'] ?>">
                    <h2><?= cacheEscape($update['title']) ?></h2>
                    <p class="meta">Published <?= cacheEscape($update['published_at']) ?> (Europe/Oslo)<?= $update['source_name'] ? ' · ' . cacheEscape($update['source_name']) : '' ?></p>
                    <?php if ($sourceUrl = cacheSourceUrl($update['url'])): ?>
                        <p><a href="<?= cacheEscape($sourceUrl) ?>" target="_blank" rel="noopener noreferrer">Read original follow-up →</a></p>
                    <?php endif; ?>
                    <?php renderCachedText($update); ?>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
