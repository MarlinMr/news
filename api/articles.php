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
require_once __DIR__ . '/article-cache.php';
require_once __DIR__ . '/article-conversion.php';

// ------------------------------------------------------------------
// 5. SETUP & MASTER CONFIG
// ------------------------------------------------------------------
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];

function articleUrl($value) {
    if (is_string($value) && preg_match('#^/?pdf/[a-f0-9]{32}\.pdf$#D', $value)) {
        return is_file(dirname(__DIR__) . '/' . ltrim($value, '/')) ? $value : false;
    }
    return filter_var($value, FILTER_VALIDATE_URL);
}

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
    if (($_GET['action'] ?? '') === 'existing_urls') {
        $start = ($_GET['start_date'] ?? '1000-01-01') . ' 00:00:00';
        $end = ($_GET['end_date'] ?? '9999-12-31') . ' 23:59:59';
        $stmt = $pdo->prepare('SELECT url FROM articles WHERE published_at BETWEEN ? AND ? UNION SELECT url FROM article_updates WHERE published_at BETWEEN ? AND ?');
        $stmt->execute([$start, $end, $start, $end]);
        echo json_encode($stmt->fetchAll()); exit;
    }
    if (($_GET['action'] ?? '') === 'resolve') {
        $destination = articleDestination($pdo, (int)($_GET['id'] ?? 0));
        if (!$destination) { http_response_code(404); echo json_encode(['error' => 'Article not found']); exit; }
        $stmt = $pdo->prepare('SELECT published_at FROM articles WHERE id = ?');
        $stmt->execute([$destination['article_id']]);
        $destination['published_at'] = $stmt->fetchColumn();
        echo json_encode($destination); exit;
    }
    if (($_GET['action'] ?? '') === 'select_existing') {
        $query = trim((string)($_GET['q'] ?? ''));
        $stmt = $pdo->prepare('SELECT a.id, a.user_id, a.title, a.url, a.latitude, a.longitude, a.published_at, u.username AS author FROM articles a LEFT JOIN users u ON u.id = a.user_id WHERE a.converted_update_id IS NULL AND a.id <> ? AND (a.title LIKE ? OR a.url LIKE ?) ORDER BY a.published_at DESC, a.id DESC LIMIT 100');
        $stmt->execute([(int)($_GET['exclude'] ?? 0), '%' . $query . '%', '%' . $query . '%']);
        echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE); exit;
    }
    if (($_GET['action'] ?? '') === 'cache_candidates') {
        if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error' => 'Please sign in first']); exit; }
        $hours = filter_var($_GET['hours'] ?? 72, FILTER_VALIDATE_INT);
        $interval = filter_var($_GET['interval'] ?? 900, FILTER_VALIDATE_INT);
        $limit = filter_var($_GET['limit'] ?? 20, FILTER_VALIDATE_INT);
        if ($hours === false || $hours < 1 || $hours > 720 || $interval === false || $interval < 60 || $interval > 86400 || $limit === false || $limit < 1 || $limit > 100) {
            http_response_code(400); echo json_encode(['error' => 'Invalid cache recheck limits']); exit;
        }
        $canCheckAll = in_array($_SESSION['user_role'] ?? 'user', ['admin', 'moderator'], true);
        $ownerFilter = $canCheckAll ? '' : 'AND user_id = ?';
        $params = [date('Y-m-d H:i:s', time() - $hours * 3600), date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() - $interval)];
        if (!$canCheckAll) $params[] = $_SESSION['user_id'];
        $where = "published_at >= ? AND published_at <= ? AND (cache_checked_at IS NULL OR cache_checked_at <= ?) AND url REGEXP '^https?://' $ownerFilter";
        $stmt = $pdo->prepare("SELECT * FROM (
            SELECT id, 'article' AS type, title, published_at, cache_checked_at FROM articles WHERE converted_update_id IS NULL AND $where
            UNION ALL
            SELECT id, 'update' AS type, title, published_at, cache_checked_at FROM article_updates WHERE $where
        ) candidates ORDER BY cache_checked_at ASC, published_at DESC, type, id LIMIT $limit");
        $stmt->execute(array_merge($params, $params));
        echo json_encode(['candidates' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE); exit;
    }
    $location = $_GET['location'] ?? 'map';
    if (!in_array($location, ['map', 'inbox', 'all'], true)) {
        http_response_code(400); echo json_encode(['error' => 'Invalid location filter']); exit;
    }
    $locationFilter = $location === 'inbox' ? 'AND a.latitude IS NULL AND a.longitude IS NULL'
        : ($location === 'map' ? 'AND a.latitude IS NOT NULL AND a.longitude IS NOT NULL' : '');
    // The inbox includes unplaced stories of all ages unless explicit dates are provided.
    $startDate = $_GET['start_date'] ?? ($location === 'inbox' ? '1000-01-01' : date('Y-m-d', strtotime('-1 year')));
    $endDate   = $_GET['end_date'] ?? ($location === 'inbox' ? '9999-12-31' : date('Y-m-d'));
    $endDateFull = $endDate . ' 23:59:59';

    // Fetch master articles, now including category_slug
    $stmt = $pdo->prepare("
        SELECT a.id, a.user_id, a.title, a.summary, a.url, a.image_url, a.emoji, a.category_slug, a.latitude, a.longitude, a.injured_count, a.killed_count, a.published_at, u.username AS author
        FROM articles a
        LEFT JOIN users u ON a.user_id = u.id
        WHERE a.published_at >= :start_date AND a.published_at <= :end_date
        AND a.converted_update_id IS NULL
        $locationFilter
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
    if (!is_array($input)) {
        http_response_code(400); echo json_encode(['error' => 'Invalid JSON payload']); exit;
    }

    if (($input['action'] ?? '') === 'convert_to_update') {
        try {
            $pdo->beginTransaction();
            $parentId = (int)($input['article_id'] ?? 0);
            $updateId = convertArticleToUpdate($pdo, (int)($input['id'] ?? 0), $parentId, $currentUserId, $currentUserRole);
            $pdo->commit();
            echo json_encode(['message' => 'Article moved to event timeline', 'article_id' => $parentId, 'update_id' => $updateId]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code($e instanceof DomainException ? 403 : ($e instanceof OutOfBoundsException ? 404 : ($e instanceof InvalidArgumentException ? 409 : 500)));
            echo json_encode(['error' => $e instanceof LogicException ? $e->getMessage() : 'Could not move article. Reload and try again.']);
        }
        exit;
    }

    // Reject stale writes to hidden identities, including add_update/comment and placement.
    $writeId = (int)($input['article_id'] ?? $input['id'] ?? 0);
    if ($writeId > 0 && !in_array($input['action'] ?? '', ['edit_comment'], true) && !(($input['action'] ?? '') === 'refresh_cache' && ($input['type'] ?? '') === 'update')) {
        $stmt = $pdo->prepare('SELECT converted_update_id FROM articles WHERE id = ?');
        $stmt->execute([$writeId]); $row = $stmt->fetch();
        if ($row && $row['converted_update_id'] !== null) { http_response_code(409); echo json_encode(['error' => 'This article is now an update. Reload its parent incident.']); exit; }
    }

    if (($input['action'] ?? '') === 'refresh_cache') {
        $type = $input['type'] ?? 'article';
        $id = (int)($input['id'] ?? 0);
        if (!in_array($type, ['article', 'update'], true) || $id <= 0) { http_response_code(400); echo json_encode(['error' => 'Invalid article type or ID']); exit; }
        $table = $type === 'article' ? 'articles' : 'article_updates';
        $stmt = $pdo->prepare("SELECT user_id FROM $table WHERE id = ?");
        $stmt->execute([$id]); $row = $stmt->fetch();
        if (!$row) { http_response_code(404); echo json_encode(['error' => 'Article not found']); exit; }
        if ($row['user_id'] != $currentUserId && !in_array($currentUserRole, ['admin', 'moderator'], true)) { http_response_code(403); echo json_encode(['error' => 'Only the author or a moderator/admin can refresh cached text']); exit; }
        session_write_close();
        $result = refreshArticleSnapshot($pdo, $type, $id);
        if ($result['status'] === 'not_found' || $result['status'] === 'source_changed') {
            http_response_code($result['status'] === 'not_found' ? 404 : 409);
            echo json_encode(['error' => 'The article was deleted or its URL changed. Reload and try again.']); exit;
        }
        echo json_encode($result); exit;
    }

    if (($input['action'] ?? '') === 'place_inbox') {
        $id = (int)($input['id'] ?? 0);
        $lat = filter_var($input['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $lng = filter_var($input['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($id <= 0 || $lat === false || $lng === false || !is_finite($lat) || !is_finite($lng) || abs($lat) > 90 || abs($lng) > 180) {
            http_response_code(400); echo json_encode(['error' => 'Invalid coordinates']); exit;
        }
        // Any signed-in user can place an unlocated story. The conditional UPDATE
        // prevents another user's concurrent placement from being overwritten.
        $stmt = $pdo->prepare('UPDATE articles SET latitude = ?, longitude = ? WHERE id = ? AND latitude IS NULL AND longitude IS NULL AND converted_update_id IS NULL');
        $stmt->execute([$lat, $lng, $id]);
        if ($stmt->rowCount() !== 1) {
            $stmt = $pdo->prepare('SELECT id FROM articles WHERE id = ?');
            $stmt->execute([$id]);
            $exists = (bool)$stmt->fetch();
            http_response_code($exists ? 409 : 404);
            echo json_encode(['error' => $exists ? 'This story has already been placed. Refresh the inbox.' : 'Article not found']); exit;
        }
        echo json_encode(['message' => 'Story placed on map', 'id' => $id]); exit;
    }

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
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT id, converted_update_id FROM articles WHERE id = ? FOR UPDATE');
            $stmt->execute([$articleId]);
            $parent = $stmt->fetch();
            if (!$parent || $parent['converted_update_id'] !== null) { $pdo->rollBack(); http_response_code(409); echo json_encode(['error' => 'Article no longer available. Reload its parent incident.']); exit; }
            $stmt = $pdo->prepare('INSERT INTO article_comments (article_id, user_id, body, published_at) VALUES (?, ?, ?, ?)');
            $stmt->execute([$articleId, $currentUserId, $body, date('Y-m-d H:i:s')]);
            $id = (int)$pdo->lastInsertId();
            $pdo->commit();
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
        $url       = articleUrl($input['url'] ?? '');
        $source    = trim($input['source_name'] ?? '');
        $pubDate   = !empty($input['published_at']) ? $input['published_at'] : date('Y-m-d H:i:s');
        if ($articleId <= 0 || empty($title) || !$url) { http_response_code(400); echo json_encode(['error' => 'Invalid update payload']); exit; }
        $snapshot = captureArticleSnapshot($url);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id, converted_update_id FROM articles WHERE id = ? FOR UPDATE');
        $stmt->execute([$articleId]); $parent = $stmt->fetch();
        if (!$parent || $parent['converted_update_id'] !== null) { $pdo->rollBack(); http_response_code(409); echo json_encode(['error' => 'Article no longer available. Reload its parent incident.']); exit; }
        $stmt = $pdo->prepare("INSERT INTO article_updates (article_id, user_id, title, url, source_name, published_at) VALUES (:article_id, :user_id, :title, :url, :source_name, :published_at)");
        $success = $stmt->execute(['article_id' => $articleId, 'user_id' => $currentUserId, 'title' => $title, 'url' => $url, 'source_name' => $source, 'published_at' => $pubDate]);
        $updateId = $pdo->lastInsertId();
        saveArticleSnapshot($pdo, 'update', $updateId, $title, $url, '', $pubDate, $snapshot);
        $pdo->commit();
        if ($success) { echo json_encode(['message' => 'Timeline update added successfully', 'id' => $updateId]); }
        else { http_response_code(500); echo json_encode(['error' => 'Failed to add timeline update']); }
        exit;
    }

    // --- CASE B: CREATE OR EDIT MASTER PIN ---
    $addInbox = ($input['action'] ?? '') === 'add_inbox';
    if (empty($input['title']) || empty($input['url']) || (!$addInbox && (!array_key_exists('latitude', $input) || !array_key_exists('longitude', $input)))) { http_response_code(400); echo json_encode(['error' => 'Missing required fields']); exit; }
    $articleId    = (isset($input['id']) && !empty($input['id'])) ? (int)$input['id'] : null;
    if ($addInbox && $articleId !== null) { http_response_code(400); echo json_encode(['error' => 'add_inbox creates a new story']); exit; }
    $title        = trim($input['title']);
    $summary      = trim($input['summary'] ?? '');
    $url          = articleUrl($input['url']);
    $imageUrl     = !empty($input['image_url']) ? filter_var($input['image_url'], FILTER_VALIDATE_URL) : null;
    $latitude     = $addInbox ? null : $input['latitude'];
    $longitude    = $addInbox ? null : $input['longitude'];
    if ($latitude !== null || $longitude !== null) {
        $latitude = filter_var($latitude, FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($longitude, FILTER_VALIDATE_FLOAT);
        if ($latitude === false || $longitude === false || !is_finite($latitude) || !is_finite($longitude) || abs($latitude) > 90 || abs($longitude) > 180) {
            http_response_code(400); echo json_encode(['error' => 'Provide valid coordinates or leave both coordinates null for the inbox']); exit;
        }
    }
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
    $snapshot = captureArticleSnapshot($url);
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO articles (user_id, title, summary, url, image_url, emoji, category_slug, latitude, longitude, injured_count, killed_count, published_at) VALUES (:user_id, :title, :summary, :url, :image_url, :emoji, :category_slug, :latitude, :longitude, :injured_count, :killed_count, :published_at)");
    $success = $stmt->execute(['user_id' => $currentUserId, 'title' => $title, 'summary' => $summary, 'url' => $url, 'image_url' => $imageUrl, 'emoji' => $emoji, 'category_slug' => $categorySlug, 'latitude' => $latitude, 'longitude' => $longitude, 'injured_count' => $injuredCount, 'killed_count' => $killedCount, 'published_at' => $publishedAt]);
    $newArticleId = $pdo->lastInsertId();
    saveArticleSnapshot($pdo, 'article', $newArticleId, $title, $url, $summary, $publishedAt, $snapshot);
    $pdo->commit();
    if ($success) { http_response_code(201); echo json_encode(['message' => 'Article created successfully', 'id' => $newArticleId]); }
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
    $stmt = $pdo->prepare("SELECT user_id, converted_update_id FROM articles WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $article = $stmt->fetch();
    if (!$article) { http_response_code(404); echo json_encode(['error' => 'Article not found']); exit; }
    if ($article['converted_update_id'] !== null) { http_response_code(409); echo json_encode(['error' => 'This article is now an update. Delete it from its parent incident instead.']); exit; }
    if ($article['user_id'] != $currentUserId && !in_array($currentUserRole, ['moderator', 'admin'])) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }
    $deleteStmt = $pdo->prepare("DELETE FROM articles WHERE id = :id");
    $deleteStmt->execute(['id' => $id]);
    echo json_encode(['message' => 'Article and timeline updates deleted successfully']);
    exit;
}
