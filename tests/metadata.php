<?php
require_once __DIR__ . '/../api/metadata.php';

$cases = [
    ['12 personer er såret', 12, null],
    ['Tre personer ble såret', 3, null],
    ['En person er såret', 1, null],
    ['5 sårede og 2 drepte', 5, 2],
    ['To personar er såra', 2, null],
    ['4 PERSONER ER SÅRET', 4, null],
    ['Three injured and two killed', 3, 2],
    ['To personer er skadde', 2, null],
    ['Flere personer er såret', null, null],
    ['Ingen såret', null, null],
];
foreach ($cases as [$text, $injured, $killed]) {
    $result = extractCasualties($text);
    if ($result['injured'] !== $injured || $result['killed'] !== $killed) {
        throw new RuntimeException('Unexpected casualty counts for: ' . $text);
    }
}
$metadata = parseArticleMetadata('<html><head><title>Tre såret i en hendelse</title><meta name="description" content="To personer er drept."></head><body></body></html>');
if ($metadata['injured'] !== 3 || $metadata['killed'] !== 2) {
    throw new RuntimeException('Metadata parsing must include såret in casualty extraction.');
}
$categoryCases = [
    // Substrings are not category evidence, even in Norwegian compounds.
    ['Brasil overrasker med ny operasjon', 'general'],
    ['Debatt om rasisme og overraskelser', 'general'],
    ['Raser mot det nye forslaget', 'general'],
    ['Observasjon av en ny stjerne', 'general'],
    ['Mistenkelig aktivitet i nabolaget', 'general'],
    ['Fire personer deltok på møtet', 'general'],
    ['En fyr på tur ved et gammelt fyr', 'general'],
    ['Ny brannmur installert', 'general'],
    ['Downtime scheduled for maintenance', 'general'],
    ['Ras stenger veien', 'landslides'],
    ['STEINRASET stengte veien', 'landslides'],
    ['Jordskredet rammet bygda', 'landslides'],
    ['Snøskredfare i fjellet', 'landslides'],
    ['Snøskredulykke i fjellet', 'landslides'],
    ['Økt rasfare etter regnet', 'landslides'],
    ['Rockslides block road', 'landslides'],
    ['Landslides and avalanches reported', 'landslides'],
    ['Nuclear explosion reported', 'nuclear'],
    ['Biologisk trussel og smittefare', 'biological-hazard'],
    ['NATO melder om missilangrep og eksplosjoner', 'missiles'],
    ['Military air strike causes fire in city', 'missiles'],
    ['Håper på våpenhvile i krigen', 'conflict'],
    ['Politiet etterforsker knivstikking', 'violent-crime'],
    ['Police investigate robbery', 'violent-crime'],
    ['Kollisjonen førte til kø', 'collisions'],
    ['Politiet melder om trafikkulykke', 'collisions'],
    ['Fire personer deltok i en operasjon', 'general'],
    ['Boligbrannen er slukket', 'fires'],
    ['Fire destroys building', 'fires'],
    ['Building on fire', 'fires'],
    ['Wildfire spreads rapidly', 'fires'],
    ['Siktet for svindel', 'crime'],
    ['Forsvaret og NATO på øvelse', 'military'],
    ['Regjeringen overrasker Brasil med ny politikk', 'politics'],
    ['Politiet informerer regjeringen om valgkampen', 'politics'],
    ['Sykehuset åpner ny avdeling', 'health'],
    ['Nye vaksiner klare', 'health'],
    ['Børsen faller etter økt inflasjon', 'finance'],
    ['Fotballaget vant mesterskapet', 'sports'],
    ['Demonstrasjoner mot vedtaket', 'protests'],
    ['Cyberangrep mot en brannmur', 'cyber'],
    ['Power outage affects thousands', 'energy'],
    ['Togstans på jernbanen', 'transport'],
    ['Ferjen grunnstøtte', 'maritime'],
    ['Veiarbeid skaper kø', 'infrastructure'],
    ['Ny teknologi og kunstig intelligens', 'tech-business'],
    ['Forskere ved universitetet har nye resultater', 'science'],
    ['Skolene får nye lærebøker', 'science'],
    ['Naturmangfoldet er truet', 'wildlife'],
    ['Festivalen åpner på museet', 'culture'],
    ['Droneobservasjoner ved flyplassen', 'drones'],
    ['Droner observert over byen', 'drones'],
    ['Farevarsel sendt ut', 'hazards'],
    ['Flomvarsel etter skybrudd', 'floods'],
    ['Tornadoes destroy homes', 'tornadoes'],
    ['Lynnedslag under tordenværet', 'thunderstorms'],
    ['Snøstormen rammer byen', 'blizzards'],
    ['High winds expected', 'winds'],
    ['Hetebølgen fortsetter', 'heatwaves'],
    ['Vulkanutbrudd på Island', 'volcanic'],
    ['Politiet har en oppdatering', 'crime'],
    ['Siste nytt: Ny informasjon', 'breaking'],
    ['Politiet starter leteaksjon etter savnet person', 'rescue'],
    ['Redningsaksjonen i fjellet er avsluttet', 'rescue'],
    ['Savnet barn funnet i god behold', 'rescue'],
    ['En turgåer er savnet i Bergen', 'rescue'],
    ['Search and rescue teams deployed', 'rescue'],
    ['Missing hikers found safe', 'rescue'],
    ['Rescue operation at sea', 'rescue'],
    ['Missing women found safe', 'rescue'],
    ['Søkeaksjon etter savnet fisker', 'rescue'],
    ['En sårt savnet artist er tilbake', 'general'],
    ['Jordskjelvet rammet byen', 'earthquakes'],
    ['Flere etterskjelv registrert', 'earthquakes'],
    ['Økt seismisk aktivitet', 'earthquakes'],
    ['Earthquake triggers landslides', 'earthquakes'],
    ['Aftershocks continue overnight', 'earthquakes'],
    ['Jordskjelvøvelse ved skolen', 'science'],
    ['Tåke fører til forsinkelser ved flyplassen', 'weather'],
    ['Glatte veier etter underkjølt regn', 'weather'],
    ['Snøfall gir vanskelige føreforhold', 'weather'],
    ['Værmeldingen varsler regnvær', 'weather'],
    ['Icy roads and freezing rain delay public transport', 'weather'],
    ['Fog disrupts flights', 'weather'],
    ['Weather forecast: snow and rain expected', 'weather'],
    ['Snøstorm og snøfall rammer byen', 'blizzards'],
    ['Flom etter mye nedbør', 'floods'],
    ['Steinras etter regnvær', 'landslides'],
    ['Heavy rain causes flooding', 'floods'],
    ['Black ice causes collision', 'collisions'],
    ['Rainforest research announced', 'science'],
];
foreach ($categoryCases as [$text, $expected]) {
    $actual = guessCategory($text);
    if ($actual !== $expected) throw new RuntimeException("Expected $expected, got $actual for: $text");
}
$metadata = parseArticleMetadata('<html><head><title>Regjeringen overrasker Brasil</title><meta name="description" content="Politisk debatt om en ny operasjon."></head></html>');
if ($metadata['category_slug'] !== 'politics') throw new RuntimeException('Metadata category must use whole-word classification.');
fwrite(STDOUT, "Metadata casualty and category tests passed.\n");
