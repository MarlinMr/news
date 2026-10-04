<?php
date_default_timezone_set('Europe/Oslo');
function fetchArticleMetadata($url) {

if (!$url || !filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'])) {
    throw new InvalidArgumentException('Invalid or missing HTTP URL');
}

$context = stream_context_create([
    'http' => [
        'timeout' => 5,
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) GeoNewsMap/1.0'
    ]
]);

$html = @file_get_contents($url, false, $context);

if (!$html) {
    throw new RuntimeException('Could not fetch metadata');
}

return parseArticleMetadata($html);
}

function parseArticleMetadata($html) {
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
$publishedAt = '';
if (!empty($rawDate)) {
    try {
        $published = new DateTimeImmutable($rawDate, new DateTimeZone('Europe/Oslo'));
        $published = $published->setTimezone(new DateTimeZone('Europe/Oslo'));
        $formattedDate = $published->format('Y-m-d');
        // A date alone does not establish a publication clock time.
        if (preg_match('/[T\s]\d{1,2}:\d{2}/', $rawDate)) {
            $formattedTime = $published->format('H:i');
            $publishedAt = $published->format(DateTimeInterface::ATOM);
        }
    } catch (Exception $e) {
        // Leave unavailable metadata empty, rather than guessing a time.
    }
}

$textToAnalyze = $title . ' ' . $description;

// Incident times in descriptions are not necessarily publication times.

// --- 1. CASUALTY EXTRACTION ---
$casualties = extractCasualties($textToAnalyze);

// --- 2. CATEGORY GUESSING ---
$guessedCategory = guessCategory($textToAnalyze);

// --- 3. GEOLOCATION GUESSING (Extract Place Name) ---
$guessedLocation = guessPlaceName($title);

return [
    'title'            => trim($title),
    'description'      => trim($description),
    'image'            => trim($image),
    'date'             => $formattedDate,
    'time'             => $formattedTime,
    'published_at'     => $publishedAt,
    'timezone'         => 'Europe/Oslo',
    'time_source'      => $formattedTime ? 'metadata' : 'unknown',
    'injured'          => $casualties['injured'],
    'killed'           => $casualties['killed'],
    'category_slug'    => $guessedCategory,
    'suggested_place'  => $guessedLocation
];
}

function getMetaTag($xpath, $property) {
    $nodes = $xpath->query("//meta[@property='$property' or @name='$property']/@content");
    return ($nodes->length > 0) ? $nodes->item(0)->nodeValue : null;
}

function extractCasualties($text) {
    $injured = null; $killed = null;
    $wordMap = ['en'=>1, 'én'=>1, 'ett'=>1, 'one'=>1, 'to'=>2, 'two'=>2, 'tre'=>3, 'three'=>3, 'fire'=>4, 'four'=>4, 'fem'=>5, 'five'=>5, 'seks'=>6, 'six'=>6, 'sju'=>7, 'syv'=>7, 'seven'=>7, 'åtte'=>8, 'eight'=>8, 'ni'=>9, 'nine' =>9, 'ti'=>10, 'ten'=>10];
    $wordRegex = implode('|', array_keys($wordMap));

    if (preg_match('/\b(\d+|' . $wordRegex . ')\s*(?:personer|personar|passasjerer)?\s*(?:(?:er|ble|blei|are|were)\s+)?(?:skadd(?:e|ede)?|injured|wounded)\b/iu', $text, $m)) {
        $val = mb_strtolower($m[1]);
        $injured = is_numeric($val) ? (int)$val : ($wordMap[$val] ?? null);
    }
    if (preg_match('/\b(\d+|' . $wordRegex . ')\s*(?:personer|personar|passasjerer)?\s*(?:(?:er|ble|blei|are|were)\s+)?(?:drept(?:e)?|omkom(?:ne)?|død(?:e)?|killed|dead|fatalities)\b/iu', $text, $m)) {
        $val = mb_strtolower($m[1]);
        $killed = is_numeric($val) ? (int)$val : ($wordMap[$val] ?? null);
    }
    return ['injured' => $injured, 'killed' => $killed];
}

// Keyword classifier logic
function guessCategory($text) {
    // Specific hazards take precedence over broad incident keywords like explosions.
    if (preg_match('/\b(?:nuclear|radiological|radioactive|radioaktiv\w*|kjernekraft\w*|atomkraft\w*|atomulykke\w*|atomvåpen\w*|strålefare|radiation\s+(?:leak|hazard|incident))\b/iu', $text)) {
        return 'nuclear';
    }
    if (preg_match('/\b(?:biohazard\w*|biological\s+(?:hazard|threat|attack|weapon\w*)|biologisk(?:e)?\s+(?:fare|trussel|angrep|våpen)|smittefare)\b/iu', $text)) {
        return 'biological-hazard';
    }
    // Match violent offences before broader rules such as police or explosions.
    if (preg_match('/\b(?:skyting\w*|skuddveksling\w*|knivstikk\w*|drap\w*|vold|volden|voldelig\w*|voldshendelse\w*|voldsforbrytelse\w*|overfall\w*|ran|ranet|ransforsøk|shooting\w*|stabb(?:ing|ed)|murder\w*|homicide\w*|assault\w*|robber(?:y|ies))\b/iu', $text)) {
        return 'violent-crime';
    }
    $rules = [
        'collisions'    => ['kollisjon', 'ulykke', 'krasj', 'derailment', 'collision', 'crash', 'avsporing', 'trafikkulykke', 'bilulykke', 'påkjørsel'],
        'fires'         => ['brann', 'skogbrann', 'eksplosjon', 'fire', 'wildfire', 'blaze', 'fyr'],
        'landslides'    => ['ras', 'jordskred', 'steinskred', 'snøskred', 'landslide', 'avalanche', 'skred'],
        'floods'        => ['flom', 'oversvømmelse', 'flood', 'heavy rain', 'skybrudd'],
        'military'      => ['forsvaret', 'militær', 'nato', 'military', 'army', 'navy', 'fregatt', 'soldat'],
        'missiles'      => ['missil', 'rakett', 'luftvern', 'missile', 'rocket', 'air strike', 'droneangrep'],
        'protests'      => ['demonstrasjon', 'protest', 'markering', 'strike', 'streik', 'opptøyer'],
        'crime'         => ['politi', 'police', 'arrested', 'siktet'],
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
