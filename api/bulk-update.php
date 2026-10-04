<?php
date_default_timezone_set('Europe/Oslo');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
session_start();
function bulkReply($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (!isset($_SESSION['user_id'])) bulkReply(['error' => 'Please log in on the main page.'], 401);
if (($_SESSION['user_role'] ?? '') !== 'admin') bulkReply(['error' => 'Admin access required.'], 403);
session_write_close();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/metadata.php';
// Reuse the category mapping without running the articles endpoint.
$categoryEmojiMap = ['general'=>'📰','breaking'=>'🔴','collisions'=>'💥','landslides'=>'⛰️','fires'=>'🔥','hazards'=>'⚠️','winds'=>'💨','tornadoes'=>'🌪️','thunderstorms'=>'🌩️','blizzards'=>'❄️','floods'=>'🌊','heatwaves'=>'☀️','volcanic'=>'🌋','drones'=>'🛸','military'=>'🎖️','missiles'=>'🚀','conflict'=>'⚔️','cyber'=>'💻','protests'=>'📢','politics'=>'📜','sports'=>'⚽','tech-business'=>'💼','finance'=>'💰','health'=>'🏥','energy'=>'⚡','transport'=>'🚌','maritime'=>'🚢','infrastructure'=>'🏗️','science'=>'🎓','wildlife'=>'🐾','culture'=>'🎨','crime'=>'⚖️','violent-crime'=>'🗡️'];
$columns = ['title'=>'title','summary'=>'summary','image'=>'image_url','category'=>'category_slug','injured'=>'injured_count','killed'=>'killed_count'];
$categoryEmojiMap['nuclear'] = '☢️';
$categoryEmojiMap['biological-hazard'] = '☣️';
function bulkValue($row, $field, $columns) {
    if ($field === 'date') return substr($row['published_at'], 0, 10);
    if ($field === 'time') return substr($row['published_at'], 11, 5);
    return $row[$columns[$field]];
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $masters = $pdo->query('SELECT id, title, url FROM articles ORDER BY published_at DESC, id DESC')->fetchAll();
    $updates = $pdo->query('SELECT id, article_id, title, url FROM article_updates ORDER BY published_at ASC, id ASC')->fetchAll();
    bulkReply(['incidents'=>$masters, 'updates'=>$updates]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') bulkReply(['error'=>'Method not allowed'], 405);
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) bulkReply(['error'=>'Invalid JSON'], 400);
$type = $input['type'] ?? '';
if (!in_array($type, ['master', 'update'], true)) bulkReply(['error'=>'Invalid article type'], 400);
$table = $type === 'master' ? 'articles' : 'article_updates';
$id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
$fields = $input['fields'] ?? [];
$allowed = $type === 'master' ? ['title','summary','image','date','time','category','injured','killed'] : ['title','date','time'];
if (!$id || $id < 1 || !is_array($fields) || !$fields || array_diff($fields, $allowed)) bulkReply(['error'=>'Invalid ID or fields'], 400);
$action = $input['action'] ?? '';
if (!in_array($action, ['preview','apply'], true)) bulkReply(['error'=>'Invalid action'], 400);
try {
    if ($action === 'apply') $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?" . ($action === 'apply' ? ' FOR UPDATE' : ''));
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) { if ($pdo->inTransaction()) $pdo->rollBack(); bulkReply(['error'=>'Article not found'], 404); }
    if ($action === 'preview') {
        $fresh = fetchArticleMetadata($row['url']);
        $values = ['title'=>$fresh['title'], 'summary'=>$fresh['description'], 'image'=>$fresh['image'], 'date'=>$fresh['date'], 'time'=>$fresh['time'], 'category'=>trim($fresh['title'].' '.$fresh['description']) ? $fresh['category_slug'] : null, 'injured'=>$fresh['injured'], 'killed'=>$fresh['killed']];
        $changes = []; $unavailable = [];
        foreach (array_unique($fields) as $field) {
            $new = $values[$field];
            if ($new === null || $new === '') { $unavailable[] = $field; continue; }
            if ($field === 'image' && !filter_var($new, FILTER_VALIDATE_URL)) { $unavailable[] = $field; continue; }
            $old = bulkValue($row, $field, $columns);
            if ((string)$old !== (string)$new) $changes[] = ['field'=>$field, 'old'=>$old, 'new'=>$new];
        }
        bulkReply(['changes'=>$changes, 'unavailable'=>$unavailable, 'url'=>$row['url']]);
    }
    $changes = $input['changes'] ?? null;
    if (!is_array($changes) || count($changes) !== count(array_unique($fields))) throw new InvalidArgumentException('Invalid changes');
    if (($input['url'] ?? '') !== $row['url']) { $pdo->rollBack(); bulkReply(['error'=>'URL changed since preview. Scan again.'], 409); }
    $data = []; $seen = [];
    $date = substr($row['published_at'],0,10); $time = substr($row['published_at'],11);
    foreach ($changes as $change) {
        $field = $change['field'] ?? '';
        if (!in_array($field,$fields,true) || isset($seen[$field]) || !array_key_exists('old',$change) || !isset($change['new']) || $change['new'] === '') throw new InvalidArgumentException('Invalid change');
        $seen[$field] = true;
        if ((string)bulkValue($row,$field,$columns) !== (string)$change['old']) { $pdo->rollBack(); bulkReply(['error'=>"$field changed since preview. Scan again."],409); }
        $new = $change['new'];
        if (!is_scalar($new)) throw new InvalidArgumentException('Invalid value');
        if ($field === 'date') {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d',(string)$new);
            if (!$d || $d->format('Y-m-d') !== $new) throw new InvalidArgumentException('Invalid date');
            $date = $new;
        } elseif ($field === 'time') {
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',(string)$new)) throw new InvalidArgumentException('Invalid time');
            $time = $new . ':00';
        } else {
            if ($field === 'image' && !filter_var($new,FILTER_VALIDATE_URL)) throw new InvalidArgumentException('Invalid image URL');
            if (in_array($field,['injured','killed'],true) && (filter_var($new,FILTER_VALIDATE_INT) === false || $new < 0)) throw new InvalidArgumentException('Invalid count');
            if ($field === 'category') {
                if (!isset($categoryEmojiMap[$new])) throw new InvalidArgumentException('Invalid category');
            }
            $data[$columns[$field]] = $new;
        }
    }
    if (isset($seen['date']) || isset($seen['time'])) $data['published_at'] = "$date $time";
    $set = implode(', ',array_map(fn($column)=>"$column = ?",array_keys($data)));
    $stmt = $pdo->prepare("UPDATE $table SET $set WHERE id = ?");
    $stmt->execute([...array_values($data),$id]);
    $pdo->commit();
    bulkReply(['message'=>'Saved', 'updated'=>count($changes)]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof InvalidArgumentException) bulkReply(['error'=>$e->getMessage()],400);
    error_log('Bulk refresh: '.$e->getMessage());
    bulkReply(['error'=>$action === 'preview' ? 'Could not fetch metadata from this article URL.' : 'Could not save changes.'],502);
}
