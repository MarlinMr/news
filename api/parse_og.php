<?php
date_default_timezone_set('Europe/Oslo');
header('Content-Type: application/json; charset=utf-8');

$url = $_GET['url'] ?? '';

if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or missing URL']);
    exit;
}

$context = stream_context_create([
    'http' => [
        'timeout' => 5,
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) GeoNewsMap/1.0'
    ]
]);

$html = @file_get_contents($url, false, $context);

if (!$html) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not fetch metadata']);
    exit;
}

$dom = new DOMDocument();
@$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
$xpath = new DOMXPath($dom);

$title       = getMetaTag($xpath, 'og:title') ?: $dom->getElementsByTagName('title')->item(0)->nodeValue ?? '';
$description = getMetaTag($xpath, 'og:description') ?: getMetaTag($xpath, 'description') ?? '';
$image       = getMetaTag($xpath, 'og:image') ?? '';

$rawDate = getMetaTag($xpath, 'article:published_time') 
        ?: getMetaTag($xpath, 'og:updated_time') 
        ?: getMetaTag($xpath, 'date') 
        ?: getMetaTag($xpath, 'pubdate') 
        ?: '';

$formattedDate = '';
$formattedTime = '';
if (!empty($rawDate)) {
    $time = strtotime($rawDate);
    if ($time !== false) {
        $formattedDate = date('Y-m-d', $time);
        $formattedTime = date('H:i', $time);
    }
}

$textToAnalyze = $title . ' ' . $description;

// Fallback: extract time from text if not found in meta tags
if (empty($formattedTime)) {
    $formattedTime = extractTimeFromText($textToAnalyze);
}

// --- 1. CASUALTY EXTRACTION ---
$casualties = extractCasualties($textToAnalyze);

// --- 2. CATEGORY GUESSING ---
$guessedCategory = guessCategory($textToAnalyze);

// --- 3. GEOLOCATION GUESSING (Extract Place Name) ---
$guessedLocation = guessPlaceName($title);

echo json_encode([
    'title'            => trim($title),
    'description'      => trim($description),
    'image'            => trim($image),
    'date'             => $formattedDate,
    'time'             => $formattedTime,
    'injured'          => $casualties['injured'],
    'killed'           => $casualties['killed'],
    'category_slug'    => $guessedCategory,
    'suggested_place'  => $guessedLocation
], JSON_UNESCAPED_UNICODE);

function getMetaTag($xpath, $property) {
    $nodes = $xpath->query("//meta[@property='$property' or @name='$property']/@content");
    return ($nodes->length > 0) ? $nodes->item(0)->nodeValue : null;
}

function extractCasualties($text) {
    $injured = null; $killed = null;
    $wordMap = ['en'=>1, 'én'=>1, 'ett'=>1, 'one'=>1, 'to'=>2, 'two'=>2, 'tre'=>3, 'three'=>3, 'fire'=>4, 'four'=>4, 'fem'=>5, 'five'=>5, 'seks'=>6, 'six'=>6, 'sju'=>7, 'syv'=>7, 'seven'=>7, 'åtte'=>8, 'eight'=>8, 'ni'=>9, 'nine' =>9, 'ti'=>10, 'ten'=>10];
    $wordRegex = implode('|', array_keys($wordMap));

    if (preg_match('/(\d+|' . $wordRegex . ')\s*(personer|personar|passasjerer)?\s*(er|ble|blei|skadd|skadde|skadede|injured|wounded)/i', $text, $m)) {
        $val = mb_strtolower($m[1]);
        $injured = is_numeric($val) ? (int)$val : ($wordMap[$val] ?? null);
    }
    if (preg_match('/(\d+|' . $wordRegex . ')\s*(personer|personar|passasjerer)?\s*(er|ble|blei|drept|drepte|omkom|omkomne|død|døde|killed|dead|fatalities)/i', $text, $m)) {
        $val = mb_strtolower($m[1]);
        $killed = is_numeric($val) ? (int)$val : ($wordMap[$val] ?? null);
    }
    return ['injured' => $injured, 'killed' => $killed];
}

// Keyword classifier logic
function guessCategory($text) {
    $rules = [
        'collisions'    => ['kollisjon', 'ulykke', 'krasj', 'derailment', 'collision', 'crash', 'avsporing', 'trafikkulykke', 'bilulykke', 'påkjørsel'],
        'fires'         => ['brann', 'skogbrann', 'eksplosjon', 'fire', 'wildfire', 'blaze', 'fyr'],
        'landslides'    => ['ras', 'jordskred', 'steinskred', 'snøskred', 'landslide', 'avalanche', 'skred'],
        'floods'        => ['flom', 'oversvømmelse', 'flood', 'heavy rain', 'skybrudd'],
        'military'      => ['forsvaret', 'militær', 'nato', 'military', 'army', 'navy', 'fregatt', 'soldat'],
        'missiles'      => ['missil', 'rakett', 'luftvern', 'missile', 'rocket', 'air strike', 'droneangrep'],
        'protests'      => ['demonstrasjon', 'protest', 'markering', 'strike', 'streik', 'opptøyer'],
        'crime'         => ['politi', 'skytine', 'knivstikking', 'ran', 'drap', 'police', 'shooting', 'stabbing', 'arrested', 'siktet'],
        'cyber'         => ['datainnbrudd', 'mistenkelig aktivitet', 'cyber', 'hack', 'outage', 'downtime', 'it-feil'],
        'drones'        => ['drone', 'uav', 'droner', 'observasjon'],
        'breaking'      => ['akutt', 'just in', 'breaking', 'siste nytt', 'ekstraordinær']
    ];

    foreach ($rules as $slug => $keywords) {
        foreach ($keywords as $kw) {
            if (mb_stripos($text, $kw) !== false) {
                return $slug;
            }
        }
    }
    return 'general';
}

// Extracts prepositions/place patterns like "i Drammen", "ved Oslo", "near London"
function guessPlaceName($title) {
    if (preg_match('/(?:i|ved|på|nær|near|in|at)\s+([A-ZÆØÅ][a-zæøåA-ZÆØÅ\-]+(?:\s+[A-ZÆØÅ][a-zæøåA-ZÆØÅ\-]+)?)/u', $title, $m)) {
        return trim($m[1]);
    }
    return null;
}

// Extract time from text (e.g., "kl. 14:30", "kl 14.30", "at 2:30 PM", "14:30")
function extractTimeFromText($text) {
    // Norwegian: kl. 14:30, kl 14.30, kl. 14.30
    if (preg_match('/kl\.?\s*(\d{1,2})[.:](\d{2})/i', $text, $m)) {
        return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
    }
    // 24h format: 14:30, 09:15
    if (preg_match('/\b(\d{1,2}):(\d{2})\b/', $text, $m)) {
        $h = (int)$m[1];
        $min = (int)$m[2];
        if ($h >= 0 && $h <= 23 && $min >= 0 && $min <= 59) {
            return sprintf('%02d:%02d', $h, $min);
        }
    }
    // 12h format: 2:30 PM, 2:30pm
    if (preg_match('/\b(\d{1,2}):(\d{2})\s*(am|pm)\b/i', $text, $m)) {
        $h = (int)$m[1];
        $min = (int)$m[2];
        $ampm = strtolower($m[3]);
        if ($ampm === 'pm' && $h !== 12) $h += 12;
        if ($ampm === 'am' && $h === 12) $h = 0;
        return sprintf('%02d:%02d', $h, $min);
    }
    return '';
}
