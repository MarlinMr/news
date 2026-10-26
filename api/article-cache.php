<?php
require_once __DIR__ . '/metadata.php';
// Every capture is immutable. Later successful checks may append a new version.
function extractCachedArticleText($html) {
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($dom);
    foreach ($xpath->query('//script|//style|//nav|//header|//footer|//aside|//form|//noscript|//template|//*[@hidden or @aria-hidden="true"]') as $node) {
        $node->parentNode->removeChild($node);
    }
    $root = $xpath->query('//*[@itemprop="articleBody"]')->item(0)
        ?: $xpath->query('//article')->item(0)
        ?: $xpath->query('//main|//*[@role="main"]')->item(0);
    if (!$root) return ''; // Do not mistake an entire site's navigation for an article.
    $blocks = [];
    foreach ($xpath->query('.//h1|.//h2|.//h3|.//h4|.//p|.//li|.//blockquote', $root) as $node) {
        // Avoid duplicating paragraphs inside list items or blockquotes.
        if ($xpath->query('ancestor::p|ancestor::li|ancestor::blockquote', $node)->length) continue;
        $text = trim(preg_replace('/\s+/u', ' ', $node->textContent));
        if ($text !== '') $blocks[] = $text;
    }
    return $blocks ? implode("\n\n", $blocks) : trim($root->textContent);
}

function fetchCachedArticleDocument($url) {
    for ($redirect = 0; $redirect <= 3; $redirect++) {
        $parts = parse_url($url);
        if (!$parts || !filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && !in_array($parts['port'], [80, 443], true))) return '';
        $host = trim($parts['host'], '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge(
            array_column(dns_get_record($host, DNS_A) ?: [], 'ip'),
            array_column(dns_get_record($host, DNS_AAAA) ?: [], 'ipv6')
        );
        if (!$addresses) return '';
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return '';
            // PHP's private/reserved flags do not reject IPv4-mapped IPv6 loopback.
            $packed = inet_pton($address);
            if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") return '';
        }
        // Pin the validated address so a second DNS lookup cannot reach a private host.
        $address = $addresses[0];
        $connectionHost = strpos($address, ':') !== false ? '[' . $address . ']' : $address;
        $connectionUrl = $parts['scheme'] . '://' . $connectionHost
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $context = stream_context_create(['http' => [
            'timeout' => 5, 'follow_location' => 0, 'ignore_errors' => true,
            'user_agent' => 'Mozilla/5.0 GeoNewsMap/1.0',
            'header' => 'Host: ' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
                . "\r\nAccept: text/html\r\nConnection: close\r\n"
        ], 'ssl' => ['peer_name' => $host, 'SNI_enabled' => true]]);
        $http_response_header = [];
        $html = @file_get_contents($connectionUrl, false, $context, 0, 5 * 1024 * 1024 + 1);
        $headers = $http_response_header;
        if (!preg_match('#^HTTP/\S+ (\d+)#', $headers[0] ?? '', $match)) return '';
        $status = (int)$match[1];
        if (in_array($status, [301, 302, 303, 307, 308], true)) {
            $location = '';
            foreach ($headers as $header) {
                if (stripos($header, 'Location:') === 0) $location = trim(substr($header, 9));
            }
            if (!$location) return '';
            $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            if (strpos($location, '//') === 0) $url = $parts['scheme'] . ':' . $location;
            elseif (strpos($location, '/') === 0) $url = $origin . $location;
            elseif (preg_match('#^https?://#i', $location)) $url = $location;
            else $url = $origin . substr($parts['path'] ?? '/', 0, strrpos($parts['path'] ?? '/', '/') + 1) . $location;
            continue;
        }
        if ($status !== 200 || !$html || strlen($html) > 5 * 1024 * 1024) return '';
        foreach ($headers as $header) {
            if (stripos($header, 'Content-Type:') === 0 && !preg_match('#(?:text/html|application/xhtml\+xml)#i', $header)) return '';
        }
        return $html;
    }
    return '';
}

function fetchCachedArticleText($url) {
    $html = fetchCachedArticleDocument($url);
    return $html === '' ? '' : extractCachedArticleText($html);
}

function captureArticleSnapshot($url) {
    $text = '';
    $metadata = [];
    try {
        if (!preg_match('#^/?pdf/#', $url)) {
            $html = fetchCachedArticleDocument($url);
            if ($html !== '') {
                $text = extractCachedArticleText($html);
                $metadata = parseArticleMetadata($html);
            }
        }
    } catch (Throwable $e) {
        error_log('Article text capture failed: ' . $e->getMessage());
    }
    return ['text' => $text, 'captured_at' => date('Y-m-d H:i:s'), 'metadata' => $metadata];
}

function saveArticleSnapshot($pdo, $type, $id, $title, $url, $summary, $publishedAt, $snapshot) {
    $versionKey = bin2hex(random_bytes(16));
    $stmt = $pdo->prepare('INSERT INTO article_snapshots (article_id, update_id, version_key, title, url, summary, published_at, article_text, captured_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$type === 'article' ? $id : null, $type === 'update' ? $id : null, $versionKey,
        $title, $url, $summary, $publishedAt, $snapshot['text'], $snapshot['captured_at']]);
    $table = $type === 'article' ? 'articles' : 'article_updates';
    $stmt = $pdo->prepare("UPDATE $table SET cache_checked_at = ? WHERE id = ?");
    $stmt->execute([$snapshot['captured_at'], $id]);
    return $versionKey;
}

function refreshArticleSnapshot($pdo, $type, $id, $capture = null) {
    if (!in_array($type, ['article', 'update'], true)) throw new InvalidArgumentException('Invalid article type');
    $table = $type === 'article' ? 'articles' : 'article_updates';
    $column = $type === 'article' ? 'article_id' : 'update_id';
    $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
    $stmt->execute([$id]);
    $source = $stmt->fetch();
    if (!$source || ($type === 'article' && isset($source['converted_update_id']))) return ['status' => 'not_found'];
    if (preg_match('#^/?pdf/#', $source['url'])) return ['status' => 'pdf'];
    // Fetch outside the transaction; lock the parent only while comparing/writing.
    $snapshot = ($capture ?? 'captureArticleSnapshot')($source['url']);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $current = $stmt->fetch();
        if (!$current) { $pdo->rollBack(); return ['status' => 'not_found']; }
        if ($type === 'article' && isset($current['converted_update_id'])) { $pdo->rollBack(); return ['status' => 'source_changed']; }
        if ($current['url'] !== $source['url']) { $pdo->rollBack(); return ['status' => 'source_changed']; }
        $stmt = $pdo->prepare("UPDATE $table SET cache_checked_at = ? WHERE id = ?");
        $stmt->execute([$snapshot['captured_at'], $id]);
        if (trim($snapshot['text']) === '') {
            $pdo->commit();
            return ['status' => 'unavailable'];
        }
        $stmt = $pdo->prepare("SELECT * FROM article_snapshots WHERE $column = ? ORDER BY captured_at DESC, id DESC LIMIT 1 FOR UPDATE");
        $stmt->execute([$id]);
        $previous = $stmt->fetch();
        $metadata = $snapshot['metadata'] ?? [];
        $title = !empty($metadata['title']) ? mb_substr($metadata['title'], 0, 255) : ($previous['title'] ?? $current['title']);
        $summary = !empty($metadata['description']) ? $metadata['description'] : ($previous['summary'] ?? $current['summary'] ?? '');
        $publishedAt = $previous['published_at'] ?? $current['published_at'];
        if (!empty($metadata['published_at'])) $publishedAt = (new DateTimeImmutable($metadata['published_at']))->setTimezone(new DateTimeZone('Europe/Oslo'))->format('Y-m-d H:i:s');
        $unchanged = $previous && $previous['article_text'] === $snapshot['text'] && $previous['title'] === $title
            && $previous['url'] === $source['url'] && $previous['summary'] === $summary && $previous['published_at'] === $publishedAt;
        if ($unchanged) {
            $pdo->commit();
            return ['status' => 'unchanged', 'version_key' => $previous['version_key']];
        }
        $key = saveArticleSnapshot($pdo, $type, $id, $title, $source['url'], $summary, $publishedAt, $snapshot);
        $pdo->commit();
        return ['status' => 'saved', 'version_key' => $key];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
