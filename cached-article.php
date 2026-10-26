<?php
date_default_timezone_set('Europe/Oslo');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/article-conversion.php';

function cacheEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function cacheSourceUrl($url) {
    if (preg_match('#^/?pdf/[a-f0-9]{32}\.pdf$#D', $url)) return ltrim($url, '/');
    if (filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)) return $url;
    return '';
}
function renderCachedText($row) {
    if ($row['versions']) {
        echo '<details class="versions"><summary>Saved versions (' . count($row['versions']) . ')</summary><ol>';
        foreach ($row['versions'] as $index => $version) {
            $number = count($row['versions']) - $index;
            $label = 'Version ' . $number . ($number === 1 ? ' · Original capture' : '') . ' · ' . $version['captured_at'] . ' (Europe/Oslo)';
            if ($version['version_key'] === $row['latest_version_key']) $label .= $version['has_text'] ? ' · Latest readable capture' : ' · Latest saved capture';
            if ($version['version_key'] === $row['version_key']) $label .= ' · Showing';
            echo '<li><a href="' . cacheEscape(cacheVersionUrl($row['cache_target'], $version['version_key'])) . '">' . cacheEscape($label) . '</a></li>';
        }
        echo '</ol><p><a href="' . cacheEscape(cacheVersionUrl($row['cache_target'], null)) . '">Show latest capture</a></p></details>';
    }
    if ($row['captured_at']) {
        echo '<p class="meta">Snapshot captured ' . cacheEscape($row['captured_at']) . ' (Europe/Oslo).</p>';
    }
    if (!empty($row['article_text'])) {
        echo '<div class="article-text">' . cacheEscape($row['article_text']) . '</div>';
    } else {
        echo '<p class="notice">' . ($row['captured_at']
            ? 'Article text could not be captured in this version. The source may require JavaScript, a subscription, or block automated access. PDF uploads remain available through the original link.'
            : 'No snapshot is available. This entry predates article text caching.') . '</p>';
        if (!empty($row['summary'])) echo '<h3>Saved summary</h3><div class="article-text">' . cacheEscape($row['summary']) . '</div>';
    }
}
function cacheVersionUrl($target, $versionKey) {
    global $id, $cacheSelections;
    $selections = $cacheSelections;
    if ($versionKey === null) unset($selections[$target]);
    else $selections[$target] = $versionKey;
    return 'cached-article.php?' . http_build_query(['id' => $id, 'versions' => $selections], '', '&', PHP_QUERY_RFC3986)
        . '#' . ($target === 'article' ? 'main-article' : $target);
}
function selectCachedVersion($row, $versions, $target) {
    global $cacheSelections, $pdo;
    $row['cache_target'] = $target;
    $row['versions'] = $versions;
    $row['article_text'] = ''; $row['captured_at'] = null; $row['version_key'] = null; $row['latest_version_key'] = null;
    if (!$versions) return $row;
    $latest = $versions[0];
    foreach ($versions as $version) {
        if ($version['has_text']) { $latest = $version; break; }
    }
    $row['latest_version_key'] = $latest['version_key'];
    $selected = $latest;
    foreach ($versions as $version) {
        if (($cacheSelections[$target] ?? '') === $version['version_key']) { $selected = $version; break; }
    }
    foreach (['title', 'url', 'summary', 'published_at', 'captured_at', 'version_key'] as $field) $row[$field] = $selected[$field];
    // Load only the displayed body, not every full-text version in the history.
    $stmt = $pdo->prepare('SELECT article_text FROM article_snapshots WHERE id = ?');
    $stmt->execute([$selected['id']]);
    $row['article_text'] = (string)$stmt->fetchColumn();
    return $row;
}
$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
$cacheSelections = [];
foreach (is_array($_GET['versions'] ?? null) ? $_GET['versions'] : [] as $target => $key) {
    if (preg_match('/^(article|update-\d+)$/D', (string)$target) && is_string($key) && preg_match('/^[a-f0-9]{32}$/D', $key)) $cacheSelections[$target] = $key;
}
$article = false;
$updates = [];
if ($id && $id > 0) {
    $destination = articleDestination($pdo, $id);
    if ($destination && $destination['update_id']) {
        if (isset($cacheSelections['article'])) {
            $cacheSelections['update-' . $destination['update_id']] = $cacheSelections['article'];
            unset($cacheSelections['article']);
        }
        header('Location: cached-article.php?' . http_build_query(['id' => $destination['article_id'], 'versions' => $cacheSelections], '', '&', PHP_QUERY_RFC3986) . '#update-' . $destination['update_id'], true, 302);
        exit;
    }
    $stmt = $pdo->prepare('SELECT id, title, url, summary, published_at FROM articles WHERE id = ? AND converted_update_id IS NULL');
    $stmt->execute([$id]);
    $article = $stmt->fetch();
    if ($article) {
        $stmt = $pdo->prepare("SELECT id, source_name, title, url, COALESCE((SELECT original.summary FROM articles original WHERE original.converted_update_id = article_updates.id ORDER BY original.id LIMIT 1), '') AS summary, published_at FROM article_updates WHERE article_id = ? ORDER BY published_at ASC, id ASC");
        $stmt->execute([$id]);
        $updates = $stmt->fetchAll();
        $stmt = $pdo->prepare('SELECT s.id, s.article_id, s.update_id, s.version_key, s.title, s.url, s.summary, s.published_at, s.captured_at, LENGTH(s.article_text) > 0 AS has_text FROM article_snapshots s LEFT JOIN article_updates au ON au.id = s.update_id WHERE s.article_id = ? OR au.article_id = ? ORDER BY s.captured_at DESC, s.id DESC');
        $stmt->execute([$id, $id]);
        $versions = [];
        foreach ($stmt->fetchAll() as $version) {
            $target = $version['article_id'] !== null ? 'article' : 'update-' . $version['update_id'];
            $versions[$target][] = $version;
        }
        $article = selectCachedVersion($article, $versions['article'] ?? [], 'article');
        foreach ($updates as &$update) {
            $target = 'update-' . $update['id'];
            $update = selectCachedVersion($update, $versions[$target] ?? [], $target);
        }
        unset($update);
        usort($updates, fn($a, $b) => strcmp($a['published_at'], $b['published_at']) ?: $a['id'] <=> $b['id']);
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
        .versions { background: #f8fafc; padding: 12px; margin: 16px 0; font-size: .9rem; border-radius: 6px; }
        .versions summary { cursor: pointer; font-weight: 600; }
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
        <p class="meta">Cached article · Showing the latest saved readable capture by default. Open Saved versions to read earlier captures. This page does not fetch live publisher content; captures may be partial.</p>
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
