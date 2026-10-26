<?php
// Portable archives contain data, never executable SQL, credentials or account roles.
const TRANSFER_MAX_ARCHIVE = 128 * 1024 * 1024;
const TRANSFER_MAX_EXPANDED = 256 * 1024 * 1024;
const TRANSFER_MAX_JSON = 64 * 1024 * 1024;
require_once __DIR__ . '/article-conversion.php';

function transferDate($value, $withTime = false) {
    $format = $withTime ? 'Y-m-d H:i:s' : 'Y-m-d';
    $date = is_string($value) ? DateTimeImmutable::createFromFormat('!' . $format, $value) : false;
    if (!$date || $date->format($format) !== $value) throw new InvalidArgumentException('Invalid date.');
    return $date;
}

function transferPdfPath($url) {
    return is_string($url) && preg_match('#^/?pdf/[a-f0-9]{32}\.pdf$#D', $url) ? ltrim($url, '/') : null;
}

function transferOrigin($pdo) {
    $stmt = $pdo->prepare('INSERT IGNORE INTO data_transfer_origin (id, origin) VALUES (1, ?)');
    $stmt->execute([bin2hex(random_bytes(16))]);
    return $pdo->query('SELECT origin FROM data_transfer_origin WHERE id = 1')->fetchColumn();
}

function transferExport($pdo, $start, $end, $pdfDirectory, $archivePath) {
    transferDate($start);
    transferDate($end);
    if ($start > $end) throw new InvalidArgumentException('Start date must be before or equal to end date.');
    $origin = transferOrigin($pdo);
    $pdo->beginTransaction();
    try {
        // Include the parent incident when a converted article falls in the range,
        // and all hidden identities belonging to each selected incident.
        $rangeStart = $pdo->quote($start . ' 00:00:00');
        $rangeEnd = $pdo->quote($end . ' 23:59:59');
        $selection = "SELECT COALESCE(u.article_id, selected.id) FROM articles selected LEFT JOIN article_updates u ON u.id = selected.converted_update_id WHERE selected.published_at >= $rangeStart AND selected.published_at <= $rangeEnd AND (selected.converted_update_id IS NULL OR u.id IS NOT NULL)";
        $rootFilter = "a.id IN ($selection)";
        $stmt = $pdo->prepare("SELECT a.* FROM articles a LEFT JOIN article_updates converted ON converted.id = a.converted_update_id WHERE $rootFilter OR converted.article_id IN ($selection) ORDER BY a.id");
        $stmt->execute();
        $articles = $stmt->fetchAll();
        $updates = []; $comments = []; $snapshots = []; $users = [];
        if ($articles) {
            // Select parents in SQL instead of expanding an unbounded ID list.
            foreach (['article_updates' => 'updates', 'article_comments' => 'comments'] as $table => $variable) {
                $stmt = $pdo->prepare("SELECT child.* FROM $table child JOIN articles a ON a.id = child.article_id WHERE $rootFilter ORDER BY child.id");
                $stmt->execute();
                $$variable = $stmt->fetchAll();
            }
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(OCTET_LENGTH(s.article_text) + OCTET_LENGTH(s.summary) + OCTET_LENGTH(s.title) + OCTET_LENGTH(s.url) + 256), 0) FROM article_snapshots s LEFT JOIN article_updates au ON au.id = s.update_id JOIN articles a ON a.id = COALESCE(s.article_id, au.article_id) WHERE $rootFilter");
            $stmt->execute();
            if ((int)$stmt->fetchColumn() > TRANSFER_MAX_JSON) throw new InvalidArgumentException('Snapshot history is too large. Export a smaller date range.');
            $stmt = $pdo->prepare("SELECT s.* FROM article_snapshots s LEFT JOIN article_updates au ON au.id = s.update_id JOIN articles a ON a.id = COALESCE(s.article_id, au.article_id) WHERE $rootFilter ORDER BY s.id");
            $stmt->execute();
            $snapshots = $stmt->fetchAll();
            $userIds = array_unique(array_column(array_merge($articles, $updates, $comments), 'user_id'));
            foreach (array_chunk($userIds, 1000) as $chunk) {
                $stmt = $pdo->prepare('SELECT id, username FROM users WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY id');
                $stmt->execute(array_values($chunk));
                $users = array_merge($users, $stmt->fetchAll());
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $data = ['format' => 'geonews', 'version' => 4, 'origin' => $origin,
        'exported_at' => date(DATE_ATOM), 'timezone' => 'Europe/Oslo',
        'range' => ['start_date' => $start, 'end_date' => $end],
        'users' => $users, 'articles' => $articles, 'updates' => $updates,
        'comments' => $comments, 'snapshots' => $snapshots];
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (strlen($json) > TRANSFER_MAX_JSON) throw new InvalidArgumentException('Too much data. Export a smaller date range.');
    $zip = new ZipArchive();
    if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not create archive.');
    try {
        if (!$zip->addFromString('data.json', $json)) throw new RuntimeException('Could not write archive data.');
        $pdfs = [];
        $expanded = strlen($json);
        foreach (array_merge($articles, $updates, $snapshots) as $row) {
            $path = transferPdfPath($row['url']);
            if (!$path || isset($pdfs[$path])) continue;
            $file = $pdfDirectory . '/' . basename($path);
            if (!is_file($file)) throw new RuntimeException('A referenced PDF is missing: ' . $path);
            $size = filesize($file);
            $expanded += $size;
            if ($size > 20 * 1024 * 1024 || $expanded > TRANSFER_MAX_EXPANDED) throw new InvalidArgumentException('Too many PDF files. Export a smaller date range.');
            if (!$zip->addFile($file, $path)) throw new RuntimeException('Could not include PDF.');
            $pdfs[$path] = true;
        }
    } finally {
        if (!$zip->close()) throw new RuntimeException('Could not finish archive.');
    }
    if (filesize($archivePath) > TRANSFER_MAX_ARCHIVE) throw new InvalidArgumentException('Archive exceeds 128 MB. Export a smaller date range.');
    return count($articles);
}

function transferText($row, $key, $max, $nullable = false) {
    $value = $row[$key] ?? null;
    if ($nullable && $value === null) return;
    if (!is_string($value) || mb_strlen($value) > $max) throw new InvalidArgumentException('Invalid or oversized field: ' . $key);
}

function transferId($id) {
    if (!is_int($id) && !(is_string($id) && ctype_digit($id))) throw new InvalidArgumentException('Invalid record ID.');
    if ($id < 1 || $id > 2147483647) throw new InvalidArgumentException('Invalid record ID.');
    return (int)$id;
}

function transferValidate($data) {
    if (!is_array($data) || ($data['format'] ?? '') !== 'geonews' || !in_array($data['version'] ?? null, [1, 2, 3, 4], true)
        || !is_string($data['origin'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $data['origin'])) {
        throw new InvalidArgumentException('Not a supported GeoNews archive.');
    }
    $indexes = [];
    foreach (['users', 'articles', 'updates', 'comments', 'snapshots'] as $group) {
        if (!isset($data[$group]) || !is_array($data[$group]) || !array_is_list($data[$group])) throw new InvalidArgumentException('Missing archive records: ' . $group);
        $indexes[$group] = [];
        foreach ($data[$group] as $row) {
            if (!is_array($row)) throw new InvalidArgumentException('Invalid archive row.');
            $id = transferId($row['id'] ?? null);
            if (isset($indexes[$group][$id])) throw new InvalidArgumentException('Duplicate record ID.');
            $indexes[$group][$id] = $row;
        }
    }
    $snapshotParents = [];
    foreach ($indexes as $group => $rows) foreach ($rows as $row) {
        if ($group === 'users') {
            transferText($row, 'username', 50);
            if ($row['username'] === '') throw new InvalidArgumentException('Empty username.');
            continue;
        }
        transferDate($row['published_at'] ?? null, true);
        if (isset($row['cache_checked_at'])) transferDate($row['cache_checked_at'], true);
        if ($group !== 'snapshots') {
            if (!isset($indexes['users'][transferId($row['user_id'] ?? null)])) throw new InvalidArgumentException('Missing author.');
        }
        if (in_array($group, ['updates', 'comments'], true) && !isset($indexes['articles'][transferId($row['article_id'] ?? null)])) throw new InvalidArgumentException('Missing parent article.');
        if ($group === 'comments') { transferText($row, 'body', 65535); continue; }
        transferText($row, 'title', 255);
        transferText($row, 'url', 500);
        $url = $row['url'];
        if (!transferPdfPath($url) && !(filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true))) throw new InvalidArgumentException('Invalid article URL.');
        if ($group === 'updates') transferText($row, 'source_name', 100, true);
        if ($group === 'articles') {
            if (isset($row['converted_update_id'])) {
                if ($data['version'] < 4) throw new InvalidArgumentException('Unsupported converted article.');
                $converted = $indexes['updates'][transferId($row['converted_update_id'])] ?? null;
                if (!$converted || $converted['article_id'] == $row['id'] || isset($indexes['articles'][$converted['article_id']]['converted_update_id'])) throw new InvalidArgumentException('Invalid converted article parent.');
            }
            transferText($row, 'summary', 65535, true);
            transferText($row, 'image_url', 500, true);
            if (!empty($row['image_url']) && !(filter_var($row['image_url'], FILTER_VALIDATE_URL) && in_array(strtolower(parse_url($row['image_url'], PHP_URL_SCHEME) ?? ''), ['http', 'https'], true))) throw new InvalidArgumentException('Invalid image URL.');
            transferText($row, 'emoji', 10);
            transferText($row, 'category_slug', 50);
            $unlocated = array_key_exists('latitude', $row) && array_key_exists('longitude', $row) && $row['latitude'] === null && $row['longitude'] === null;
            if (!$unlocated || $data['version'] === 1) {
                foreach (['latitude' => 90, 'longitude' => 180] as $key => $limit) {
                    if (!isset($row[$key]) || !is_numeric($row[$key]) || !is_finite((float)$row[$key]) || abs((float)$row[$key]) > $limit) throw new InvalidArgumentException('Invalid coordinates.');
                }
            }
            foreach (['injured_count', 'killed_count'] as $key) {
                if (!isset($row[$key]) || filter_var($row[$key], FILTER_VALIDATE_INT) === false || $row[$key] < 0 || $row[$key] > 2147483647) throw new InvalidArgumentException('Invalid casualty count.');
            }
        }
        if ($group === 'snapshots') {
            transferText($row, 'summary', 65535);
            transferText($row, 'article_text', 16777215);
            transferDate($row['captured_at'] ?? null, true);
            $isArticle = isset($row['article_id']);
            if ($isArticle === isset($row['update_id'])) throw new InvalidArgumentException('Invalid snapshot parent.');
            $parent = transferId($row[$isArticle ? 'article_id' : 'update_id']);
            if (!isset($indexes[$isArticle ? 'articles' : 'updates'][$parent])) throw new InvalidArgumentException('Missing snapshot parent.');
            $key = ($isArticle ? 'article:' : 'update:') . $parent;
            if ($data['version'] >= 3) {
                if (!is_string($row['version_key'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $row['version_key'])) throw new InvalidArgumentException('Invalid snapshot version key.');
                $key .= ':' . $row['version_key'];
            }
            if (isset($snapshotParents[$key])) throw new InvalidArgumentException('Duplicate snapshot.');
            $snapshotParents[$key] = true;
        }
    }
    return $indexes;
}

function transferReadArchive($archivePath) {
    if (filesize($archivePath) > TRANSFER_MAX_ARCHIVE) throw new InvalidArgumentException('Maximum archive size is 128 MB.');
    $zip = new ZipArchive();
    if ($zip->open($archivePath) !== true) throw new InvalidArgumentException('Select a valid ZIP archive.');
    try {
        $expanded = 0; $names = [];
        if ($zip->numFiles > 10000) throw new InvalidArgumentException('Too many archive files.');
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'];
            if (($name !== 'data.json' && !transferPdfPath($name)) || strpos($name, '/') === 0 || isset($names[$name])) throw new InvalidArgumentException('Unexpected or duplicate archive path.');
            $limit = $name === 'data.json' ? TRANSFER_MAX_JSON : 20 * 1024 * 1024;
            $expanded += $stat['size'];
            if ($stat['size'] > $limit || $expanded > TRANSFER_MAX_EXPANDED) throw new InvalidArgumentException('Expanded archive is too large.');
            $names[$name] = true;
        }
        $json = $zip->getFromName('data.json');
        if ($json === false) throw new InvalidArgumentException('Archive is missing data.json.');
        $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        transferValidate($data);
        return [$zip, $data, $names];
    } catch (Throwable $e) {
        $zip->close();
        throw $e;
    }
}

function transferImport($pdo, $archivePath, $pdfDirectory) {
    [$zip, $data, $names] = transferReadArchive($archivePath);
    $origin = $data['origin'];
    $createdFiles = [];
    $maps = []; $pdfMap = [];
    $counts = ['articles' => 0, 'updates' => 0, 'comments' => 0, 'snapshots' => 0, 'pdfs' => 0, 'located' => 0, 'skipped' => 0];
    $tables = ['users' => 'users', 'articles' => 'articles', 'updates' => 'article_updates', 'comments' => 'article_comments'];
    $convertedIdentities = [];
    foreach ($data['articles'] as $identity) if (isset($identity['converted_update_id'])) $convertedIdentities[$identity['converted_update_id']] = $identity['id'];
    $columns = [
        'articles' => ['user_id', 'title', 'summary', 'url', 'image_url', 'emoji', 'category_slug', 'latitude', 'longitude', 'injured_count', 'killed_count', 'published_at', 'cache_checked_at'],
        'updates' => ['article_id', 'user_id', 'title', 'url', 'source_name', 'published_at', 'cache_checked_at'],
        'comments' => ['article_id', 'user_id', 'body', 'published_at']
    ];
    try {
        $pdo->beginTransaction();
        // Serialize imports, including imports from the same origin, without exposing a new permission surface.
        $localOrigin = transferOrigin($pdo);
        $pdo->query('SELECT id FROM data_transfer_origin WHERE id = 1 FOR UPDATE')->fetch();
        if ($origin === $localOrigin) throw new InvalidArgumentException('This archive comes from this database. Import it into a different installation.');
        foreach (array_merge($data['articles'], $data['updates'], $data['snapshots']) as $row) {
            $path = transferPdfPath($row['url']);
            if (!$path || isset($pdfMap[$path])) continue;
            if (!isset($names[$path])) throw new InvalidArgumentException('Archive is missing a referenced PDF: ' . $path);
            $content = $zip->getFromName($path);
            if ($content === false || substr($content, 0, 5) !== '%PDF-' || (new finfo(FILEINFO_MIME_TYPE))->buffer($content) !== 'application/pdf') throw new InvalidArgumentException('Invalid PDF in archive.');
            // Stable names make repeated imports idempotent and avoid overwriting local PDFs.
            $newPath = 'pdf/' . substr(hash('sha256', $origin . $path . hash('sha256', $content)), 0, 32) . '.pdf';
            $destination = $pdfDirectory . '/' . basename($newPath);
            if (!is_dir($pdfDirectory) && !@mkdir($pdfDirectory, 0755, true) && !is_dir($pdfDirectory)) throw new RuntimeException('Could not create PDF storage.');
            if (is_file($destination)) {
                if (hash_file('sha256', $destination) !== hash('sha256', $content)) throw new RuntimeException('Conflicting PDF in destination.');
            } else {
                $stream = @fopen($destination, 'xb');
                if (!$stream) throw new RuntimeException('Could not write PDF.');
                $createdFiles[] = $destination;
                try {
                    if (fwrite($stream, $content) !== strlen($content)) throw new RuntimeException('Could not write complete PDF.');
                } finally { fclose($stream); }
                $counts['pdfs']++;
            }
            $pdfMap[$path] = $newPath;
        }
        foreach ($tables as $group => $table) {
            $maps[$group] = [];
            foreach ($data[$group] as $row) {
                $stmt = $pdo->prepare("SELECT m.local_id FROM data_transfer_records m JOIN $table t ON t.id = m.local_id WHERE m.origin = ? AND m.record_type = ? AND m.source_id = ? FOR UPDATE");
                $stmt->execute([$origin, $group, $row['id']]);
                $localId = $stmt->fetchColumn();
                // A local conversion and a later source conversion of the same
                // imported article must share one update, not create a duplicate.
                if (!$localId && $group === 'updates' && isset($convertedIdentities[$row['id']])) {
                    $destination = articleDestination($pdo, $maps['articles'][$convertedIdentities[$row['id']]]);
                    if ($destination && $destination['update_id']) {
                        $localId = $destination['update_id'];
                        $stmt = $pdo->prepare('INSERT INTO data_transfer_records (origin, record_type, source_id, local_id) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE local_id = VALUES(local_id)');
                        $stmt->execute([$origin, $group, $row['id'], $localId]);
                    }
                }
                if (!$localId) {
                    $values = $row;
                    if ($group === 'users') {
                        $username = $row['username'];
                        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
                        $stmt->execute([$username]);
                        if ($stmt->fetch()) {
                            $username = mb_substr($row['username'], 0, 24) . '~' . substr($origin, 0, 8) . '-' . $row['id'];
                            $stmt->execute([$username]);
                            while ($stmt->fetch()) {
                                $username = mb_substr($row['username'], 0, 24) . '~' . bin2hex(random_bytes(8));
                                $stmt->execute([$username]);
                            }
                        }
                        // No source passwords, email addresses or administrative privileges are copied.
                        $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)');
                        $stmt->execute([$username, $origin . '-' . $row['id'] . '@offline.invalid', password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), 'user']);
                    } else {
                        $values['user_id'] = $maps['users'][$row['user_id']];
                        if (isset($row['article_id'])) {
                            $mappedParent = $maps['articles'][$row['article_id']];
                            $destination = articleDestination($pdo, $mappedParent);
                            $values['article_id'] = $destination ? $destination['article_id'] : $mappedParent;
                        }
                        if (isset($row['url']) && ($path = transferPdfPath($row['url']))) $values['url'] = $pdfMap[$path];
                        $fields = $columns[$group];
                        $stmt = $pdo->prepare("INSERT INTO $table (" . implode(',', $fields) . ') VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')');
                        $stmt->execute(array_map(fn($field) => $values[$field] ?? null, $fields));
                        $counts[$group]++;
                    }
                    $localId = (int)$pdo->lastInsertId();
                    $stmt = $pdo->prepare('INSERT INTO data_transfer_records (origin, record_type, source_id, local_id) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE local_id = VALUES(local_id)');
                    $stmt->execute([$origin, $group, $row['id'], $localId]);
                } elseif ($group !== 'users') {
                    $located = false;
                    if ($group === 'articles' && isset($row['latitude'], $row['longitude'])) {
                        // Fill only an unlocated destination. The conditional UPDATE also
                        // preserves a local placement made concurrently with this import.
                        $stmt = $pdo->prepare('UPDATE articles SET latitude = ?, longitude = ? WHERE id = ? AND latitude IS NULL AND longitude IS NULL AND converted_update_id IS NULL');
                        $stmt->execute([$row['latitude'], $row['longitude'], $localId]);
                        $located = $stmt->rowCount() === 1;
                        if ($located) $counts['located']++;
                    }
                    if (!$located) $counts['skipped']++;
                }
                $maps[$group][$row['id']] = (int)$localId;
            }
        }
        foreach ($data['snapshots'] as $row) {
            $isArticle = isset($row['article_id']);
            $column = $isArticle ? 'article_id' : 'update_id';
            $localId = $maps[$isArticle ? 'articles' : 'updates'][$row[$column]];
            $url = ($path = transferPdfPath($row['url'])) ? $pdfMap[$path] : $row['url'];
            $versionKey = $data['version'] >= 3 ? $row['version_key'] : substr(hash('sha256', 'geonews-legacy-snapshot:' . $origin . ':' . $row['id']), 0, 32);
            // Version keys preserve distinct captures even if content/timestamps repeat.
            // Content matching is only needed for old archives without version keys.
            if ($data['version'] >= 3) {
                $stmt = $pdo->prepare("SELECT id FROM article_snapshots WHERE $column = ? AND version_key = ?");
                $stmt->execute([$localId, $versionKey]);
            } else {
                $stmt = $pdo->prepare("SELECT id FROM article_snapshots WHERE $column = ? AND (version_key = ? OR (BINARY title = ? AND BINARY url = ? AND BINARY summary = ? AND published_at = ? AND BINARY article_text = ? AND captured_at = ?)) LIMIT 1");
                $stmt->execute([$localId, $versionKey, $row['title'], $url, $row['summary'], $row['published_at'], $row['article_text'], $row['captured_at']]);
            }
            if ($stmt->fetch()) continue;
            $stmt = $pdo->prepare('INSERT INTO article_snapshots (article_id, update_id, version_key, title, url, summary, published_at, article_text, captured_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$isArticle ? $localId : null, $isArticle ? null : $localId, $versionKey, $row['title'], $url, $row['summary'], $row['published_at'], $row['article_text'], $row['captured_at']]);
            $counts['snapshots']++;
        }
        foreach ($data['articles'] as $row) {
            $localId = $maps['articles'][$row['id']];
            if (isset($row['converted_update_id'])) {
                $destination = articleDestination($pdo, $localId);
                if ($destination && !$destination['update_id']) {
                    $updateId = $maps['updates'][$row['converted_update_id']];
                    $stmt = $pdo->prepare('SELECT article_id FROM article_updates WHERE id = ?');
                    $stmt->execute([$updateId]);
                    $parentId = (int)$stmt->fetchColumn();
                    $parent = articleDestination($pdo, $parentId);
                    convertArticleToUpdate($pdo, $localId, $parent['article_id'], null, 'user', $updateId);
                }
            }
            reconcileConvertedArticle($pdo, $localId);
        }
        $pdo->commit();
        return $counts;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($createdFiles as $file) @unlink($file);
        throw $e;
    } finally { $zip->close(); }
}
