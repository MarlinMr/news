<?php
// Docker-only startup configuration; the source HTML keeps CDN URLs for hosted installs.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$mode = getenv('FRONTEND_ASSETS') ?: 'cdn';
if (!in_array($mode, ['cdn', 'local'], true)) {
    fwrite(STDERR, "FRONTEND_ASSETS must be 'cdn' or 'local'.\n");
    exit(1);
}
$html = file_get_contents('/usr/local/share/geonews/index.html');
if ($html === false) exit(1);
if ($mode === 'local') {
    $assets = [
        'https://unpkg.com/leaflet@1.9.4/dist/' => 'vendor/leaflet-1.9.4/dist/',
        'https://unpkg.com/leaflet.markercluster@1.5.3/dist/' => 'vendor/leaflet.markercluster-1.5.3/dist/',
    ];
    $html = str_replace(array_keys($assets), array_values($assets), $html);
}
if (file_put_contents('/var/www/html/index.html', $html) === false) exit(1);
