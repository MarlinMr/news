<?php
// Exercise the actual API against a disposable database with legacy mixed collations.
if (!getenv('TEST_DB_HOST') || !getenv('TEST_DB_PASSWORD')) {
    fwrite(STDERR, "Set TEST_DB_HOST and TEST_DB_PASSWORD to a disposable MariaDB server.\n");
    exit(1);
}
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$root = new PDO('mysql:host=' . getenv('TEST_DB_HOST') . ';charset=utf8mb4', 'root', getenv('TEST_DB_PASSWORD'), $options);
$database = 'collation_test_' . bin2hex(random_bytes(4));
function collationRequest($query, $database) {
    $code = '$_SERVER["REQUEST_METHOD"] = "GET"; $_GET = ' . var_export($query, true) . ';'
        . 'session_start(); $_SESSION["user_id"] = 1; $_SESSION["user_role"] = "admin"; session_write_close();'
        . 'require ' . var_export(__DIR__ . '/../api/articles.php', true) . ';';
    $environment = array_merge(getenv(), ['MARIADB_HOST' => getenv('TEST_DB_HOST'), 'MARIADB_PORT' => '3306',
        'MARIADB_DATABASE' => $database, 'MARIADB_USER' => 'root', 'MARIADB_PASSWORD' => getenv('TEST_DB_PASSWORD')]);
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0 || $errors !== '') throw new RuntimeException($output . $errors);
    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}
try {
    $root->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; USE `$database`");
    $root->exec(file_get_contents(__DIR__ . '/../docker/schema.sql'));
    foreach (['utf8mb4_general_ci' => 'utf8mb4', 'utf8_general_ci' => 'utf8'] as $collation => $charset) {
        $root->exec("ALTER TABLE article_updates MODIFY title VARCHAR(255) CHARACTER SET $charset COLLATE $collation NOT NULL, MODIFY url VARCHAR(500) CHARACTER SET $charset COLLATE $collation NOT NULL");
        $root->exec("UPDATE article_updates SET title = 'Oppdatering fra Ålesund' WHERE id = 1");
        $urls = collationRequest(['action' => 'existing_urls'], $database);
        if (count($urls) !== 3 || !in_array('https://www.nrk.no', array_column($urls, 'url'), true)) throw new RuntimeException('URL lookup must retain and deduplicate both tables.');
        $response = collationRequest(['action' => 'cache_candidates'], $database);
        $candidates = $response['candidates'] ?? [];
        if (count($candidates) !== 5 || count(array_filter($candidates, fn($row) => $row['type'] === 'update')) !== 2) throw new RuntimeException('Cache candidates must include both tables.');
        if (!in_array('Oppdatering fra Ålesund', array_column($candidates, 'title'), true)) throw new RuntimeException('Unicode titles must survive collation conversion.');
        $limited = collationRequest(['action' => 'cache_candidates', 'limit' => 1], $database);
        if (count($limited['candidates']) !== 1) throw new RuntimeException('Candidate limit must still apply.');
    }
    fwrite(STDOUT, "Mixed-collation article API tests passed.\n");
} finally {
    $root->exec("DROP DATABASE IF EXISTS `$database`");
}
