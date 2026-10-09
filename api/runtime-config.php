<?php
// Public browser settings only; never expose database credentials here.
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store');
$providers = json_decode(getenv('TILE_SERVERS') ?: '{}', true);
if (!is_array($providers)) $providers = [];
$config = [
    'nominatimUrl' => rtrim(getenv('NOMINATIM_URL') ?: 'https://nominatim.openstreetmap.org', '/'),
    'tileServers' => $providers,
    'tileServerUrl' => getenv('TILE_SERVER_URL') ?: '',
    'tileServerAttribution' => getenv('TILE_SERVER_ATTRIBUTION') ?: '',
    'railwayTileUrl' => getenv('RAILWAY_TILE_URL') ?: 'https://{s}.tiles.openrailwaymap.org/standard/{z}/{x}/{y}.png',
];
echo 'window.GEONEWS_CONFIG = ' . json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) . ';';
