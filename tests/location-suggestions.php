<?php
require_once __DIR__ . '/../api/metadata.php';

$cases = [
    ['Tønsberg: Ung MC-fører livstruende skadet', '', 'Tønsberg'],
    ['London: Three injured in crash', '', 'London'],
    ['Brann i campingvogn', 'Brannen er i Kviteseid kommune.', 'Kviteseid'],
    ['Fire destroys building', 'Crews were called to Bristol.', 'Bristol'],
    ['Brann i eit lokale', 'Det brenn i Haram på Sunnmøre.', 'Haram'],
    ['Evakuering av Olesjky starter fredag', '', 'Oleshky, Ukraine'],
    ['Evacuation of New York begins', '', 'New York'],
    ['Angrep utenfor Kyiv', '', 'Kyiv'],
    ['Ship attacked off Dover', '', 'Dover'],
    ['Fire on Oxford Street', '', 'Oxford Street'],
    ['Ulykke utanfor Bergen', '', 'Bergen'],
    ['Storm in UK', '', 'United Kingdom'],
    ['Protests in US', '', 'United States'],
    ['Crash near São Paulo', '', 'São Paulo'],
    ['Røykutvikling på Majorstuen i Oslo', '', 'Majorstuen, Oslo'],
    ['Fire in Richmond in London', '', 'Richmond, London'],
    ['Angrep på Gaza', '', 'Gaza Strip, Palestine'],
    ['Tankskip angrepet i Hormuzstredet', '', 'Strait of Hormuz'],
    ['Skip angrepet i Persiabukta', '', 'Persian Gulf'],
    ['Høyesterett avviser millionkrav fra advokat Arvid Sjødin', '', null],
    ['Midlertidig stans i Meta-endringer', '', null],
    ['Kreml: Europa kan være til stede ved Ukraina-samtaler', '', null],
    ['Alle dei pågripne i Nato-saka blir lauslatne', '', null],
    ['Fire people attended the meeting', '', null],
    ['Ny rapport om inflasjon', '', null],
];
foreach ($cases as [$title, $summary, $expected]) {
    $actual = guessPlaceCandidates($title, $summary)[0]['query'] ?? null;
    if ($actual !== $expected) throw new RuntimeException("$title: expected " . json_encode($expected) . ', got ' . json_encode($actual));
}
$multiple = guessPlaceCandidates('Protests in London and Paris', 'Police responded in London.');
if (array_column($multiple, 'place') !== ['London', 'Paris']) throw new RuntimeException('Separate multiple places and deduplicate repeated evidence.');
$region = guessPlaceCandidates('Brann i Møre og Romsdal');
if ($region[0]['place'] !== 'Møre og Romsdal') throw new RuntimeException('Keep compound region names intact.');
$summary = guessPlaceCandidates('Brann i campingvogn', 'Brann i Kviteseid.');
if ($summary[0]['source'] !== 'summary' || $summary[0]['confidence'] !== 'review') throw new RuntimeException('Summary-only guesses require review.');
$context = guessPlaceCandidates('Fire in Richmond in London');
if ($context[0]['query'] !== 'Richmond, London') throw new RuntimeException('Prefer qualified place names.');
$metadata = parseArticleMetadata('<html><head><title>Brann i campingvogn</title><meta name="description" content="Brann i Kviteseid."></head></html>');
if ($metadata['suggested_place'] !== 'Kviteseid' || !$metadata['location_suggestions']) throw new RuntimeException('Preserve legacy metadata field alongside new suggestions.');
echo "Location suggestion tests passed.\n";
