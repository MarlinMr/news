<?php
// Converted articles retain their identity/metadata for bookmarks and old imports.
// Their visible content lives in a real article_updates row.
function articleDestination($pdo, $id) {
    $stmt = $pdo->prepare('SELECT a.id, a.converted_update_id, u.article_id AS parent_id FROM articles a LEFT JOIN article_updates u ON u.id = a.converted_update_id WHERE a.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return false;
    return $row['converted_update_id'] === null ? ['article_id' => (int)$row['id'], 'update_id' => null]
        : ($row['parent_id'] ? ['article_id' => (int)$row['parent_id'], 'update_id' => (int)$row['converted_update_id']] : false);
}

// Caller owns the transaction. Always lock article rows in ID order.
function convertArticleToUpdate($pdo, $sourceId, $parentId, $userId = null, $role = 'user', $existingUpdateId = null) {
    if ($sourceId <= 0 || $parentId <= 0 || $sourceId === $parentId) throw new InvalidArgumentException('Choose two different articles.');
    $stmt = $pdo->prepare('SELECT * FROM articles WHERE id IN (?, ?) ORDER BY id FOR UPDATE');
    $stmt->execute([$sourceId, $parentId]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) $rows[(int)$row['id']] = $row;
    if (!isset($rows[$sourceId], $rows[$parentId])) throw new OutOfBoundsException('Article not found.');
    $source = $rows[$sourceId]; $parent = $rows[$parentId];
    if ($source['converted_update_id'] !== null || $parent['converted_update_id'] !== null) throw new InvalidArgumentException('An article has already been moved. Reload and try again.');
    if ($userId !== null && $source['user_id'] != $userId && !in_array($role, ['admin', 'moderator'], true)
        && ($source['latitude'] !== null || $source['longitude'] !== null)) throw new DomainException('Only the author or a moderator/admin can move a located article.');
    if ($existingUpdateId === null) {
        $stmt = $pdo->prepare('INSERT INTO article_updates (article_id, user_id, title, url, source_name, published_at, cache_checked_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$parentId, $source['user_id'], $source['title'], $source['url'], mb_substr(parse_url($source['url'], PHP_URL_HOST) ?: '', 0, 100), $source['published_at'], $source['cache_checked_at']]);
        $existingUpdateId = (int)$pdo->lastInsertId();
    } else {
        $stmt = $pdo->prepare('SELECT id FROM article_updates WHERE id = ? AND article_id = ?');
        $stmt->execute([$existingUpdateId, $parentId]);
        if (!$stmt->fetch()) throw new InvalidArgumentException('Invalid converted update.');
    }
    $stmt = $pdo->prepare('UPDATE articles SET converted_update_id = ? WHERE id = ?');
    $stmt->execute([$existingUpdateId, $sourceId]);
    reconcileConvertedArticle($pdo, $sourceId);
    return $existingUpdateId;
}

// Also used after importing an older archive into a locally converted incident.
function reconcileConvertedArticle($pdo, $id) {
    $destination = articleDestination($pdo, $id);
    if (!$destination || !$destination['update_id']) return;
    $stmt = $pdo->prepare('UPDATE article_updates SET article_id = ? WHERE article_id = ?');
    $stmt->execute([$destination['article_id'], $id]);
    $stmt = $pdo->prepare('UPDATE article_comments SET article_id = ? WHERE article_id = ?');
    $stmt->execute([$destination['article_id'], $id]);
    // Imports may have already supplied the same capture under its update identity.
    $stmt = $pdo->prepare('DELETE s FROM article_snapshots s JOIN article_snapshots other ON other.update_id = ? AND other.version_key = s.version_key WHERE s.article_id = ?');
    $stmt->execute([$destination['update_id'], $id]);
    $stmt = $pdo->prepare('UPDATE article_snapshots SET article_id = NULL, update_id = ? WHERE article_id = ?');
    $stmt->execute([$destination['update_id'], $id]);
}
