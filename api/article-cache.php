<?php
// Snapshots are written only on insertion, never by metadata refreshes or edits.
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

function fetchCachedArticleText($url) {
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
        return extractCachedArticleText($html);
    }
    return '';
}

function captureArticleSnapshot($url) {
    $text = '';
    try {
        if (!preg_match('#^/?pdf/#', $url)) $text = fetchCachedArticleText($url);
    } catch (Throwable $e) {
        error_log('Article text capture failed: ' . $e->getMessage());
    }
    return ['text' => $text, 'captured_at' => date('Y-m-d H:i:s')];
}

function saveArticleSnapshot($pdo, $type, $id, $title, $url, $summary, $publishedAt, $snapshot) {
    $stmt = $pdo->prepare('INSERT INTO article_snapshots (article_id, update_id, title, url, summary, published_at, article_text, captured_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$type === 'article' ? $id : null, $type === 'update' ? $id : null,
        $title, $url, $summary, $publishedAt, $snapshot['text'], $snapshot['captured_at']]);
}
