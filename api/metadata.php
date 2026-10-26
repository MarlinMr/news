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

    if (preg_match('/\b(\d+|' . $wordRegex . ')\s*(?:person(?:er|ar)?|passasjer(?:er)?)?\s*(?:(?:er|ble|blei|are|were)\s+)?(?:skadd(?:e|ede)?|såret|sårede|såra|injured|wounded)\b/iu', $text, $m)) {
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
    // First match wins: specific threats/attacks first, then common incidents,
    // then broader news topics. Police/breaking-news mentions are only fallbacks.
    // Patterns match complete Unicode words; inflections/compounds are explicit.
    $rules = [
        'nuclear' => 'nuclear|radiological|radioactive|radioaktiv\p{L}*|kjernekraft\p{L}*|atomkraft\p{L}*|atomulykke\p{L}*|atomvåpen\p{L}*|strålefare|radiation\s+(?:leak|hazard|incident)',
        'biological-hazard' => 'biohazard\p{L}*|biological\s+(?:hazard|threat|attack|weapon\p{L}*)|biologisk(?:e)?\s+(?:fare|trussel|angrep|våpen)|smittefare',
        'missiles' => 'missil\p{L}*|rakett(?:en|er|ene|angrep\p{L}*)?|luftvern\p{L}*|missiles?|rocket\s+attacks?|air\s*strikes?|droneangrep\p{L}*',
        'conflict' => 'krig(?:en|er|ene|føring|shandling\p{L}*)?|væpnet\s+konflikt|våpenhvile\p{L}*|invasjon\p{L}*|war|ceasefire|invasion|armed\s+conflict',
        'earthquakes' => 'jordskjelv(?:et|ene)?|jordskjelvsverm(?:en|er)?|etterskjelv(?:et|ene)?|earthquakes?|aftershocks?|seismisk(?:e)?\s+aktivitet|seismic\s+activity',
        'violent-crime' => 'skyting\p{L}*|skuddveksling\p{L}*|knivstikk\p{L}*|drap\p{L}*|vold|volden|voldelig\p{L}*|voldshendelse\p{L}*|voldsforbrytelse\p{L}*|overfall\p{L}*|ran|ranet|ransforsøk|shooting\p{L}*|stabb(?:ing|ed)|murder\p{L}*|homicide\p{L}*|assault\p{L}*|robber(?:y|ies)',
        'rescue' => '(?:lete|leite|rednings|søke)aksjon(?:en|er|ene)?|sjøredning|fjellredning|search\s+and\s+rescue|rescue\s+(?:operation|mission)s?|missing\s+(?:persons?|people|child|children|hikers?|men|women|man|woman)|savnet(?:e)?\s+(?:person(?:er)?|barn|mann|kvinne|jente|gutt|turgåer|fisker|tenåring)|(?:person(?:er)?|barn|mann|kvinne|jente|gutt|turgåer|fisker|tenåring)\s+(?:er\s+)?savnet',
        'collisions' => 'kollisjon(?:en|er|ene)?|(?:trafikk|bil|buss|tog|fly|båt)?ulykke(?:n|r|ne)?|krasj(?:et)?|derailments?|collisions?|crash(?:es|ed)?|avsporing(?:en|er)?|påkjørsel(?:en|er)?|påkjørt',
        // Bare Norwegian "fire" (four), "fyr" (lighthouse/guy), and
        // "brannmur" (firewall) are not evidence of an actual fire.
        'fires' => '(?:skog|bolig|hus|bygnings|bil|lyng|gress)?brann(?:en|er|ene|ar|ane)?|eksplosjon(?:en|er|ene)?|explosions?|wildfires?|blazes?|on\s+fire|(?:house|building|forest)\s+fire|fire\s+(?:in|at|breaks|broke|destroys|engulfs|kills)',
        'landslides' => '(?:stein|jord|leir|snø|fjell|sørpe)?ras(?:et|ene|fare|varsel|ulykke(?:n|r|ne)?)?|(?:jord|stein|snø|fjell|leir|sørpe)?skred(?:et|ene|fare|varsel|ulykke(?:n|r|ne)?)?|landslides?|rockslides?|avalanches?',
        'floods' => 'flom(?:men|mer|mene|fare|varsel)?|oversvømmelse(?:n|r|ne)?|flood(?:s|ing|ed)?|heavy\s+rain|skybrudd',
        'tornadoes' => 'tornado(?:en|er|es|s)?|skypumpe(?:n|r|ne)?',
        'thunderstorms' => 'tordenvær(?:et)?|lynnedslag(?:et|ene)?|thunderstorms?|lightning\s+strikes?',
        'blizzards' => 'snøstorm(?:en|er|ene)?|ekstremkulde|sprengkulde|blizzards?|extreme\s+cold',
        'winds' => 'orkan(?:en|er|ene)?|storm(?:en|er|ene)?|sterk\s+vind|kraftig\s+vind|gales?|high\s+winds?|hurricanes?',
        'heatwaves' => 'hetebølge(?:n|r|ne)?|ekstremvarme|heat\s*waves?|extreme\s+heat',
        'volcanic' => 'vulkan(?:en|er|ene|utbrudd\p{L}*)?|volcanic\s+eruption\p{L}*|volcano(?:es)?',
        'weather' => 'værmelding(?:en|er|ene)?|vêrvarsel(?:et|a)?|værforhold(?:ene)?|føreforhold(?:ene)?|glatt(?:e)?\s+(?:veier|vegar|føre)|glattføre|isete\s+(?:veier|vegar)|underkjølt\s+regn|tåke(?:n)?|snøfall(?:et)?|snøvær|regnvær|nedbør(?:en)?|weather\s+(?:forecast|conditions?)|travel\s+conditions?|fog(?:gy)?|snow(?:fall)?|rain(?:fall)?|icy\s+roads?|black\s+ice|freezing\s+rain',
        'cyber' => 'datainnbrudd(?:et|ene)?|dataangrep(?:et|ene)?|cyber\p{L}*|hackerangrep\p{L}*|hacket|hacking|ransomware|løsepengevirus|it-feil|data(?:brudd|lekkasje)\p{L}*|data\s+breach(?:es)?|it\s+outage',
        'protests' => 'demonstrasjon(?:en|er|ene)?|protest(?:en|er|ene|s|ers)?|streik(?:en|er|ende)?|opptøyer|rallies|labor\s+strike|labour\s+strike',
        'crime' => 'siktet|tiltalt|pågrepet|arrested|tyveri(?:et|er|ene)?|innbrudd(?:et|ene)?|svindel(?:en)?|bedrageri(?:et|er|ene)?|rettssak(?:en|er|ene)?|theft|fraud|burglary|indicted',
        'drones' => 'drone(?:n|r|ne|s)?|uav|droneobservasjon(?:en|er|ene)?',
        'military' => 'forsvaret|militær\p{L}*|nato|military|army|navy|fregatt(?:en|er|ene)?|soldat(?:en|er|ene)?',
        'politics' => 'storting(?:et|svalg)?|regjering(?:en|a|er|ene)?|statsminister(?:en)?|president(?:en|er|ene)?|politikk|politisk(?:e)?|valgkamp(?:en)?|kommunevalg(?:et)?|presidentvalg(?:et)?|elections?|parliament|government|prime\s+minister',
        'health' => 'sykehus(?:et|ene)?|sjukehus(?:et|a)?|helsevesen(?:et)?|folkehelse|pandemi(?:en|er)?|epidemi(?:en|er)?|vaksine(?:n|r|ne)?|hospitals?|public\s+health|vaccines?|pandemic',
        'finance' => 'rente(?:n|r|ne|kutt\p{L}*|økning\p{L}*)?|inflasjon(?:en)?|økonomi(?:en|sk|ske)?|børs(?:en|fall|oppgang)?|aksje(?:r|ne|kurs\p{L}*)|inflation|interest\s+rates?|stock\s+market|economy',
        'sports' => 'fotball\p{L}*|håndball\p{L}*|idrett(?:en|sarrangement\p{L}*)?|mesterskap(?:et|ene)?|olympi\p{L}*|football|soccer|championships?|olympics',
        'energy' => 'strømbrudd(?:et|ene)?|straumbrot(?:et|a)?|strømpris(?:en|er|ene)?|kraftverk(?:et|ene)?|vindkraft\p{L}*|power\s+(?:outage|plant)s?|electricity\s+prices?',
        'transport' => 'kollektivtrafikk(?:en)?|togtrafikk(?:en)?|togstans|flyplass(?:en|er|ene)?|flytrafikk(?:en)?|innstilte\s+(?:tog|fly|busser)|jernbane(?:n)?|public\s+transport|railway|airports?',
        'maritime' => 'skipsfart(?:en)?|skipsforlis(?:et|ene)?|grunnstøt\p{L}*|ferje(?:n|r|ne)?|ferge(?:n|r|ne)?|lasteskip(?:et|ene)?|shipping|shipwrecks?|maritime',
        'infrastructure' => 'veiarbeid(?:et|ene)?|vegarbeid(?:et|a)?|vannbrudd(?:et|ene)?|vannledning(?:en|er|ene)?|brobygging|infrastruktur(?:en)?|infrastructure|construction',
        'tech-business' => 'teknologi(?:en|sk|ske)?|kunstig\s+intelligens|oppstartsbedrift(?:en|er|ene)?|konkurs(?:en|er|ene)?|technology|artificial\s+intelligence|bankruptcy',
        'science' => 'forskning(?:en|a)?|forskar(?:ar|ane)?|forsker(?:e|en|ne)?|universitet(?:et|ene)?|vitenskap\p{L}*|skole(?:n|r|ne)?|skule(?:n|r|ne)?|science|research|university|schools?|education',
        'wildlife' => 'naturvern(?:et)?|naturmangfold(?:et)?|dyreliv(?:et)?|rovvilt(?:et)?|ulv(?:en|er|ene)?|wildlife|endangered\s+species',
        'culture' => 'kultur(?:en)?|konsert(?:en|er|ene)?|festival(?:en|er|ene)?|museum|museet|teater(?:et)?|concerts?|museums?|theatre|theater',
        'hazards' => 'farevarsel(?:et|ene)?|evakuer(?:t|te|ing|ingen)|evacuat(?:ed|ion|ions)|hazard\s+warning',
        'crime-fallback' => 'politi(?:et)?|police',
        'breaking' => 'akutt|just\s+in|breaking(?:\s+news)?|siste\s+nytt|ekstraordinær(?:t|e)?'
    ];

    foreach ($rules as $slug => $pattern) {
        if (preg_match('~(?<![\p{L}\p{N}_])(?:' . $pattern . ')(?![\p{L}\p{N}_])~iu', $text)) {
            return $slug === 'crime-fallback' ? 'crime' : $slug;
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
