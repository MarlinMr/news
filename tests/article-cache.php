<?php
require_once __DIR__ . '/../api/article-cache.php';

function checkCache($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}

$text = extractCachedArticleText('<html><body><nav>Navigation</nav><article><h1>Nyheter fra Ålesund</h1><p>First <strong>paragraph</strong>.</p><blockquote><p>Quoted text.</p></blockquote><ul><li><p>List text.</p></li></ul><aside>Advertising</aside><p hidden>Hidden text</p><script>alert("bad")</script><footer>Footer</footer></article></body></html>');
checkCache($text === "Nyheter fra Ålesund\n\nFirst paragraph.\n\nQuoted text.\n\nList text.", 'Extract readable text without navigation, scripts, hidden content, or duplicate paragraphs');
checkCache(extractCachedArticleText('<main><p>Main content</p></main>') === 'Main content', 'Support main content when article is absent');
checkCache(extractCachedArticleText('<div itemprop="articleBody">Plain article text</div>') === 'Plain article text', 'Support articleBody without paragraph markup');
checkCache(extractCachedArticleText('<body><nav>Menu</nav><p>Sign in</p></body>') === '', 'Do not cache arbitrary site chrome as an article');
foreach (['file:///etc/passwd', 'http://127.0.0.1/', 'http://[::1]/', 'http://[::ffff:127.0.0.1]/', 'http://10.0.0.1/', 'https://example.com:8080/'] as $url) {
    checkCache(fetchCachedArticleText($url) === '', 'Reject non-public source: ' . $url);
}
$snapshot = captureArticleSnapshot('pdf/' . str_repeat('a', 32) . '.pdf');
checkCache($snapshot['text'] === '' && !empty($snapshot['captured_at']), 'PDFs record a capture attempt without parsing binary data');
fwrite(STDOUT, "Article cache tests passed.\n");
