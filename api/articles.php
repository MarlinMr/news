<?php
date_default_timezone_set('Europe/Oslo');
// api/articles.php - BULLETPROOF CORS VERSION
// Location: Line 1, Character 1 must be '<?php'

// ------------------------------------------------------------------
// 1. ABSOLUTE TOP: MANDATORY CORS HEADERS
// This block MUST run before ANY other include or logic.
// ------------------------------------------------------------------

// Allow requests from any origin (or specify your frontend domain)
header('Access-Control-Allow-Origin: *'); 

// Allowed methods
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS'); 

// Allowed headers (crucial for JSON payloads)
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Cache preflight response for 1 hour (optional improvement)
header('Access-Control-Max-Age: 3600');

// ------------------------------------------------------------------
// 2. IMMEDIATE HANDLING OF PREFLIGHT (OPTIONS) REQUEST
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // If preflight, exit cleanly right now. Do not include db.php.
    http_response_code(200);
    exit;
}

// ------------------------------------------------------------------
// 3. SECURE SESSION START
// ------------------------------------------------------------------
session_start();

// ------------------------------------------------------------------
// 4. LOAD DEPENDENCIES (Safe now, after Preflight Check)
// ------------------------------------------------------------------
// Ensure db.php is sanitized (from Step 1)
require_once __DIR__ . '/db.php';

// ------------------------------------------------------------------
// 5. SETUP & MASTER CONFIG
// ------------------------------------------------------------------
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];

// Master mapping of shortname slugs to Windows-compatible emojis.
$categoryEmojiMap = [
    'general'        => '📰',
    'breaking'       => '🔴',
    'collisions'     => '💥',
    'landslides'     => '⛰️',
    'fires'          => '🔥',
    'hazards'        => '⚠️',
    'nuclear'        => '☢️',
    'biological-hazard' => '☣️',
    'winds'          => '💨',
    'tornadoes'      => '🌪️',
    'thunderstorms'  => '🌩️',
    'blizzards'      => '❄️',
    'floods'         => '🌊',
    'heatwaves'      => '☀️',
    'volcanic'       => '🌋',
    'drones'         => '🛸',
    'military'       => '🎖️',
    'missiles'       => '🚀',
    'conflict'       => '⚔️',
    'cyber'          => '💻',
    'protests'       => '📢',
    'politics'       => '📜',
    'sports'         => '⚽',
    'tech-business'  => '💼',
    'finance'        => '💰',
    'health'         => '🏥',
    'energy'         => '⚡',
    'transport'      => '🚌',
    'maritime'       => '🚢',
    'infrastructure' => '🏗️',
    'science'        => '🎓',
    'wildlife'       => '🐾',
    'culture'        => '🎨',
    'crime'          => '⚖️',
    'violent-crime'  => '🗡️'
];

// ------------------------------------------------------------------
// 6. GET: FETCH ARTICLES (WITH TIMELINE UPDATES)
// ------------------------------------------------------------------
if ($method === 'GET') {
    $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-1 year'));
    $endDate   = $_GET['end_date'] ?? date('Y-m-d');
    $endDateFull = $endDate . ' 23:59:59';

    // Fetch master articles, now including category_slug
    $stmt = $pdo->prepare("
        SELECT a.id, a.user_id, a.title, a.summary, a.url, a.image_url, a.emoji, a.category_slug, a.latitude, a.longitude, a.injured_count, a.killed_count, a.published_at, u.username AS author
        FROM articles a
        LEFT JOIN users u ON a.user_id = u.id
        WHERE a.published_at >= :start_date AND a.published_at <= :end_date
        ORDER BY a.published_at DESC
    ");
    $stmt->execute(['start_date' => $startDate, 'end_date' => $endDateFull]);
    $articles = $stmt->fetchAll();

    // Fetch timeline updates (Sub-Articles)
    if (!empty($articles)) {
        $articleIds = array_column($articles, 'id');
        $inQuery = implode(',', array_fill(0, count($articleIds), '?'));
        $updateStmt = $pdo->prepare("
            SELECT au.id, au.article_id, au.user_id, au.title, au.url, au.source_name, au.published_at, u.username AS author
            FROM article_updates au
            LEFT JOIN users u ON au.user_id = u.id
            WHERE au.article_id IN ($inQuery)
            ORDER BY au.published_at ASC
        ");
        $updateStmt->execute($articleIds);
        $allUpdates = $updateStmt->fetchAll();
        foreach ($allUpdates as &$update) { $update['type'] = 'article'; }
        unset($update);
        $commentStmt = $pdo->prepare("SELECT c.id, c.article_id, c.user_id, c.body, c.body AS title, '' AS url, '' AS source_name, c.published_at, u.username AS author, 'comment' AS type FROM article_comments c LEFT JOIN users u ON c.user_id = u.id WHERE c.article_id IN ($inQuery)");
        $commentStmt->execute($articleIds);
        $allUpdates = array_merge($allUpdates, $commentStmt->fetchAll());
        usort($allUpdates, fn($a, $b) => strcmp($a['published_at'], $b['published_at']));
        $updatesByArticle = [];
        foreach ($allUpdates as $upd) { $updatesByArticle[$upd['article_id']][] = $upd; }
        foreach ($articles as &$art) { $art['updates'] = $updatesByArticle[$art['id']] ?? []; }
    }
    echo json_encode($articles, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------------------------------------------------
// WRITE AUTH CHECK ( Safe now db.php is loaded)
// ------------------------------------------------------------------
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized: Please log in first.']);
    exit;
}
$currentUserId   = $_SESSION['user_id'];
$currentUserRole = $_SESSION['user_role'] ?? 'user';

// ------------------------------------------------------------------
// 7. POST: CREATE/UPDATE MASTER PIN OR ADD A SUB-TIMELINE UPDATE
// ------------------------------------------------------------------
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (in_array($input['action'] ?? '', ['add_comment', 'edit_comment'], true)) {
        $body = trim($input['body'] ?? '');
        if ($body === '' || mb_strlen($body) > 5000) {
            http_response_code(400); echo json_encode(['error' => 'Comment must contain 1–5000 characters']); exit;
        }
        if ($input['action'] === 'edit_comment') {
            $id = (int)($input['id'] ?? 0);
            $stmt = $pdo->prepare('SELECT user_id FROM article_comments WHERE id = ?');
            $stmt->execute([$id]);
            $comment = $stmt->fetch();
            if (!$comment || $comment['user_id'] != $currentUserId) {
                http_response_code($comment ? 403 : 404); echo json_encode(['error' => 'Only the author can edit this comment']); exit;
            }
            $stmt = $pdo->prepare('UPDATE article_comments SET body = ? WHERE id = ?');
            $stmt->execute([$body, $id]);
        } else {
            $articleId = (int)($input['article_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT id FROM articles WHERE id = ?');
            $stmt->execute([$articleId]);
            if (!$stmt->fetch()) { http_response_code(404); echo json_encode(['error' => 'Article not found']); exit; }
            $stmt = $pdo->prepare('INSERT INTO article_comments (article_id, user_id, body, published_at) VALUES (?, ?, ?, ?)');
            $stmt->execute([$articleId, $currentUserId, $body, date('Y-m-d H:i:s')]);
            $id = (int)$pdo->lastInsertId();
        }
        echo json_encode(['message' => 'Comment saved', 'id' => $id]); exit;
    }

    if (($input['action'] ?? '') === 'move_pin') {
        $id = (int)($input['id'] ?? 0);
        $lat = filter_var($input['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $lng = filter_var($input['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($id <= 0 || $lat === false || $lng === false || abs($lat) > 90 || abs($lng) > 180) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid coordinates']);
            exit;
        }
        $stmt = $pdo->prepare('SELECT user_id FROM articles WHERE id = ?');
        $stmt->execute([$id]);
        $article = $stmt->fetch();
        if (!$article || ($article['user_id'] != $currentUserId && !in_array($currentUserRole, ['admin', 'moderator']))) {
            http_response_code($article ? 403 : 404);
            echo json_encode(['error' => $article ? 'Forbidden' : 'Article not found']);
            exit;
        }
        $stmt = $pdo->prepare('UPDATE articles SET latitude = ?, longitude = ? WHERE id = ?');
        $stmt->execute([$lat, $lng, $id]);
        echo json_encode(['message' => 'Position saved']);
        exit;
    }

    // --- CASE A: ADD A SUB-TIMELINE UPDATE TO AN EXISTING MASTER PIN ---
    if (isset($input['action']) && $input['action'] === 'add_update') {
        $articleId = (int)($input['article_id'] ?? 0);
        $title     = trim($input['title'] ?? '');
        $url       = filter_var($input['url'] ?? '', FILTER_VALIDATE_URL);
        $source    = trim($input['source_name'] ?? '');
        $pubDate   = !empty($input['published_at']) ? $input['published_at'] : date('Y-m-d H:i:s');
        if ($articleId <= 0 || empty($title) || !$url) { http_response_code(400); echo json_encode(['error' => 'Invalid update payload']); exit; }
        $stmt = $pdo->prepare("INSERT INTO article_updates (article_id, user_id, title, url, source_name, published_at) VALUES (:article_id, :user_id, :title, :url, :source_name, :published_at)");
        $success = $stmt->execute(['article_id' => $articleId, 'user_id' => $currentUserId, 'title' => $title, 'url' => $url, 'source_name' => $source, 'published_at' => $pubDate]);
        if ($success) { echo json_encode(['message' => 'Timeline update added successfully', 'id' => $pdo->lastInsertId()]); } 
        else { http_response_code(500); echo json_encode(['error' => 'Failed to add timeline update']); }
        exit;
    }

    // --- CASE B: CREATE OR EDIT MASTER PIN ---
    if (empty($input['title']) || empty($input['url']) || !isset($input['latitude']) || !isset($input['longitude'])) { http_response_code(400); echo json_encode(['error' => 'Missing required fields']); exit; }
    $articleId    = (isset($input['id']) && !empty($input['id'])) ? (int)$input['id'] : null;
    $title        = trim($input['title']);
    $summary      = trim($input['summary'] ?? '');
    $url          = filter_var($input['url'], FILTER_VALIDATE_URL);
    $imageUrl     = !empty($input['image_url']) ? filter_var($input['image_url'], FILTER_VALIDATE_URL) : null;
    $latitude     = (float)$input['latitude'];
    $longitude    = (float)$input['longitude'];
    $injuredCount = isset($input['injured_count']) ? max(0, (int)$input['injured_count']) : 0;
    $killedCount  = isset($input['killed_count']) ? max(0, (int)$input['killed_count']) : 0;
    $publishedAt  = !empty($input['published_at']) ? $input['published_at'] : date('Y-m-d H:i:s');
    $requestedSlug = isset($input['category_slug']) ? trim($input['category_slug']) : 'general';
    if (array_key_exists($requestedSlug, $categoryEmojiMap)) { $categorySlug = $requestedSlug; $emoji = $categoryEmojiMap[$requestedSlug]; } 
    else { $categorySlug = 'general'; $emoji = $categoryEmojiMap['general']; }
    // Emoji is an independent marker choice. Keep category defaults for older clients.
    if (array_key_exists('emoji', $input)) {
        $requestedEmoji = $input['emoji'];
        if (!is_string($requestedEmoji) || mb_strlen($requestedEmoji) > 10 || !preg_match('/^(?=[\s\S]*[\p{Extended_Pictographic}\p{Regional_Indicator}])[\p{Extended_Pictographic}\p{Regional_Indicator}\x{FE0F}\x{FE0E}\x{200D}\x{1F3FB}-\x{1F3FF}]+$/u', $requestedEmoji)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid marker emoji']);
            exit;
        }
        $emoji = $requestedEmoji;
    }
    if (!$url) { http_response_code(400); echo json_encode(['error' => 'Invalid URL']); exit; }

    // UPDATE
    if ($articleId !== null && $articleId > 0) {
        $checkStmt = $pdo->prepare("SELECT user_id, emoji FROM articles WHERE id = :id");
        $checkStmt->execute(['id' => $articleId]);
        $existing = $checkStmt->fetch();
        if (!$existing) { http_response_code(404); echo json_encode(['error' => 'Article not found to update']); exit; }
        if ($existing['user_id'] != $currentUserId && !in_array($currentUserRole, ['moderator', 'admin'])) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }
        if (!array_key_exists('emoji', $input)) $emoji = $existing['emoji'];
        $stmt = $pdo->prepare("UPDATE articles SET title = :title, summary = :summary, url = :url, image_url = :image_url, emoji = :emoji, category_slug = :category_slug, latitude = :latitude, longitude = :longitude, injured_count = :injured_count, killed_count = :killed_count, published_at = :published_at WHERE id = :id");
        $success = $stmt->execute(['id' => $articleId, 'title' => $title, 'summary' => $summary, 'url' => $url, 'image_url' => $imageUrl, 'emoji' => $emoji, 'category_slug' => $categorySlug, 'latitude' => $latitude, 'longitude' => $longitude, 'injured_count' => $injuredCount, 'killed_count' => $killedCount, 'published_at' => $publishedAt]);
        if ($success) { echo json_encode(['message' => 'Article updated successfully', 'id' => $articleId]); } 
        else { http_response_code(500); echo json_encode(['error' => 'Failed to update article']); }
        exit;
    } 

    // INSERT
    $stmt = $pdo->prepare("INSERT INTO articles (user_id, title, summary, url, image_url, emoji, category_slug, latitude, longitude, injured_count, killed_count, published_at) VALUES (:user_id, :title, :summary, :url, :image_url, :emoji, :category_slug, :latitude, :longitude, :injured_count, :killed_count, :published_at)");
    $success = $stmt->execute(['user_id' => $currentUserId, 'title' => $title, 'summary' => $summary, 'url' => $url, 'image_url' => $imageUrl, 'emoji' => $emoji, 'category_slug' => $categorySlug, 'latitude' => $latitude, 'longitude' => $longitude, 'injured_count' => $injuredCount, 'killed_count' => $killedCount, 'published_at' => $publishedAt]);
    if ($success) { http_response_code(201); echo json_encode(['message' => 'Article created successfully', 'id' => $pdo->lastInsertId()]); } 
    else { http_response_code(500); echo json_encode(['error' => 'Failed to save article']); }
    exit;
}

// ------------------------------------------------------------------
// 8. DELETE: REMOVE A MASTER PIN OR TIMELINE UPDATE
// ------------------------------------------------------------------
if ($method === 'DELETE') {
    $type = $_GET['type'] ?? 'article';
    $id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'Invalid ID']); exit; }
    if ($type === 'comment') {
        $stmt = $pdo->prepare('SELECT c.user_id, a.user_id AS owner_id FROM article_comments c JOIN articles a ON a.id = c.article_id WHERE c.id = ?');
        $stmt->execute([$id]);
        $comment = $stmt->fetch();
        if (!$comment) { http_response_code(404); echo json_encode(['error' => 'Comment not found']); exit; }
        if ($comment['user_id'] != $currentUserId && $comment['owner_id'] != $currentUserId && !in_array($currentUserRole, ['moderator', 'admin'])) {
            http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit;
        }
        $stmt = $pdo->prepare('DELETE FROM article_comments WHERE id = ?');
        $stmt->execute([$id]);
        echo json_encode(['message' => 'Comment deleted']); exit;
    }
    if ($type === 'update') {
        $checkStmt = $pdo->prepare("SELECT user_id FROM article_updates WHERE id = :id");
        $checkStmt->execute(['id' => $id]);
        $update = $checkStmt->fetch();
        if (!$update) { http_response_code(404); echo json_encode(['error' => 'Timeline update not found']); exit; }
        if ($update['user_id'] != $currentUserId && !in_array($currentUserRole, ['moderator', 'admin'])) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }
        $stmt = $pdo->prepare("DELETE FROM article_updates WHERE id = :id");
        $stmt->execute(['id' => $id]);
        echo json_encode(['message' => 'Timeline update deleted']);
        exit;
    }
    $stmt = $pdo->prepare("SELECT user_id FROM articles WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $article = $stmt->fetch();
    if (!$article) { http_response_code(404); echo json_encode(['error' => 'Article not found']); exit; }
    if ($article['user_id'] != $currentUserId && !in_array($currentUserRole, ['moderator', 'admin'])) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }
    $deleteStmt = $pdo->prepare("DELETE FROM articles WHERE id = :id");
    $deleteStmt->execute(['id' => $id]);
    echo json_encode(['message' => 'Article and timeline updates deleted successfully']);
    exit;
}
