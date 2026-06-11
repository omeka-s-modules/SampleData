<?php
/**
 * Civilizations dataset media strategy.
 *
 * qid_overrides: hardcoded Wikidata QIDs for items the entity search cannot find
 * (titles differ from Wikidata labels, ambiguous matches, etc.).
 */
return [
    ['type' => 'use_alt_labels'],
    ['type' => 'strip_suffixes', 'suffixes' => [
        'Civilization', 'Civilisation', 'Culture', 'Kingdom', 'Empire',
        'Dynasty', 'Confederation', 'Republic', 'Sultanate', 'Khanate',
        'Khaganate', 'State', 'Chiefdoms', 'City-States', 'Polity',
        'Principality', 'Duchy', 'County', 'Caliphate', 'Emirate',
        'Commonwealth', 'Tsardom', 'Shogunate', 'Federation', 'League',
        'Peoples', 'Tribe', 'Period',
    ]],
    ['type' => 'field_order', 'fields' => [
        'id', 'class', 'sets', 'relations', 'dcterms:identifier',
        'map_bounds', 'map_coordinates',
        'dcterms:title', 'dcterms:description', 'dcterms:subject', 'dcterms:date',
        'dcterms:extent', 'dcterms:temporal', 'sample-data:peakDate', 'sample-data:area',
        'media',
    ]],
    ['type' => 'override', 'commons_file_overrides' => [
        'huari-tiwanaku-interaction-sphere' => 'Map of Wari and Tiawaku.svg',
        'bamar-konbaung-dynasty' => 'Konbaung dynasty.png',
    ]],
    ['type' => 'override', 'qid_overrides' => [
        'lydian-kingdom' => 'Q620765',  // Wikidata label is "Lydia", not "Lydian Kingdom"
        'avar-khaganate' => 'Q28411',
        'khazar-khaganate' => 'Q2090473',
        'bamar-konbaung-dynasty' => 'Q1062422', // Wikidata label is "Konbaung dynasty"
    ], 'property' => 'P18'],
    // QID overrides for dcterms:identifier injection (build-identifiers.php).
    // Corrects false positives from the automatic SPARQL passes (wrong entity matched via
    // alt-label or stripped-suffix), and pins items where entity search is unreliable.
    ['type' => 'override', 'qid_overrides' => [
        // Wrong auto-matches: alt-label or suffix-strip returned a language, city, or modern state
        'french-empire-napoleon'       => 'Q71084',    // First French Empire (auto: Q150 French language)
        'georgian-kingdom'             => 'Q154667',   // Kingdom of Georgia (auto: Q8108 Georgian language)
        'dahomey-kingdom'              => 'Q468814',   // Dahomey (auto: Q962 Benin/modern state)
        'dahomey-kingdom-early-modern' => 'Q468814',   // same entity, early modern period
        'chim-kingdom'                 => 'Q581741',   // Chimor / Chimú Kingdom (auto: Q103897334 encyclopedia article)
        'g-kt-rk-khaganate'            => 'Q15146034', // Turkic Khaganate (auto: Q1559285 given name)
        'aksum-empire'                 => 'Q139377',   // Kingdom of Aksum (auto: Q5832 city of Axum)
        'swahili-city-states'          => 'Q2739014',  // Swahili coast (auto: Q7838 Swahili language)
        'tlaxcala-republic'            => 'Q614072',   // Tlaxcala Nahua state (auto: Q82681 modern Mexican state)
        'median-empire'                => 'Q20437507', // Median Kingdom (auto: Q8735 Medes people)
        'viking-age-scandinavia'       => 'Q213649',   // Viking Age (auto: Q139707911 stub)
        // Not found by automatic passes — entity search unreliable at run time
        'gaya-confederacy'             => 'Q28084',
        'joseon-dynasty-early-modern'  => 'Q28179',    // same entity as joseon-dynasty
        'qing-dynasty-early-modern'    => 'Q409962',   // same entity as qing-dynasty

        // Ancient Mediterranean (title differs from Wikidata label or was not found automatically)
        'kingdom-of-macedon'           => 'Q83958',    // Macedon (ancient Hellenic kingdom)
        'kingdom-of-epirus'            => 'Q11266977', // Epirus (ancient Greek state)
        'antigonid-macedonia'          => 'Q237325',   // Antigonid dynasty (no dedicated state item exists)
        'kingdom-of-the-bosporus'      => 'Q321371',   // Bosporan Kingdom
        'kingdom-of-thrace'            => 'Q870517',   // Odrysian kingdom (principal Thracian polity)
        'acarnanian-confederation'     => 'Q24915809', // Acarnanian League

        // Ancient Near East
        'early-dynastic-sumer'         => 'Q716742',   // Early Dynastic period (Mesopotamia)
        'kassite-babylon'              => 'Q16630263', // Kassite dynasty
        'kingdom-of-aram-damascus'     => 'Q625649',   // Aram-Damascus
        'early-dynastic-egypt'         => 'Q187979',   // Early Dynastic Period of Egypt
        'egyptian-old-kingdom'         => 'Q177819',   // Old Kingdom of Egypt
        'egyptian-middle-kingdom'      => 'Q191324',   // Middle Kingdom of Egypt
        'egyptian-new-kingdom'         => 'Q180568',   // New Kingdom of Egypt
        'late-period-of-ancient-egypt' => 'Q621917',   // Late Period of ancient Egypt
        '25th-dynasty-of-egypt'        => 'Q737648',   // Twenty-fifth Dynasty of Egypt
        'old-elamite-kingdom'          => 'Q29297301', // Old Elamite period
        'kingdom-of-yamhad'            => 'Q617218',   // Yamhad
        'city-of-ashur'                => 'Q200200',   // Assur (ancient city, first Assyrian capital)
        'kingdom-of-bit-adini'         => 'Q878717',   // Bit Adini
        'neo-hittite-kingdoms'         => 'Q770281',   // Neo-Hittite states

        // Medieval and early modern Europe
        'kingdom-of-denmark-norway'    => 'Q62651',    // Denmark-Norway
        'kingdom-of-galicia-volhynia'  => 'Q239502',   // Kingdom of Galicia-Volhynia
        'hundred-years-war-france-england' => 'Q12551', // Hundred Years' War
        'crown-of-aragon-sicily'       => 'Q204920',   // Crown of Aragon
        'grand-principality-of-finland'=> 'Q62633',    // Grand Duchy of Finland (standard English label)
        'papal-avignon-period'         => 'Q202558',   // Avignon papacy
        'bohemian-hussite-movement'    => 'Q131372',   // Hussites (the movement)
        'kingdom-of-navarre-france'    => 'Q200262',   // Kingdom of Navarre
        'albanian-league-of-lezh'      => 'Q669241',   // League of Lezhë
        'waldemar-iv-denmark'          => 'Q216630',   // Valdemar IV of Denmark (king, not kingdom)

        // Central and South Asia / Islamic world
        'qarakhanid-khanate'           => 'Q494354',   // Karakhanid Khanate (Wikidata label differs)
        'ismaili-nizari-state'         => 'Q6563843',  // Nizari Ismaili state (Alamut)
        'imamate-of-muscat'            => 'Q1752110',  // Yaruba dynasty (no dedicated Imamate of Muscat item)
        'sikh-khalsa-confederacy'      => 'Q2578028',  // Sikh Confederacy
        'afghan-durrani-empire'        => 'Q467627',   // Durrani Empire

        // East and Southeast Asia
        'kingdom-of-qin'               => 'Q34756',    // State of Qin
        'kingdom-of-wei'               => 'Q912052',   // Wei (Warring States)
        'kingdom-of-ryukyu'            => 'Q28025',    // Ryukyu Kingdom
        'later-three-kingdoms-of-korea'=> 'Q698268',   // Later Three Kingdoms
        'mahajanapadas'                => 'Q846025',   // Mahajanapada
        'dvaravati-kingdom'            => 'Q1268307',  // Dvaravati
        'sailendra-dynasty'            => 'Q1148477',  // Shailendra dynasty
        'i-c-vi-t'                     => 'Q10841085', // Đại Cồ Việt
        'mon-hanthawaddy-kingdom'      => 'Q1572529',  // Hanthawaddy Kingdom
        'l-dynasty-vietnam'            => 'Q878276',   // Later Lê dynasty

        // Africa
        'zagwe-dynasty'                => 'Q140446',   // Zagwe dynasty
        'kingdom-of-buganda'           => 'Q473748',   // Buganda
        'kingdom-of-luba'              => 'Q1768252',  // Luba Empire
        'kingdom-of-kuba'              => 'Q209327',   // Kuba Kingdom
        'funj-sultanate'               => 'Q1475713',  // Sennar Sultanate (Funj Sultanate)

        // Period-of and thematic entries — best available entity where no dedicated article exists
        'toltec-chichimec-state'                => 'Q187897',    // Toltec civilization (no Toltec-Chichimec state item)
        'hurrian-states'                        => 'Q190394',    // Hurrians (umbrella for multiple Hurrian polities)
        'mughal-india-under-akbar'              => 'Q8597',      // Akbar (the defining figure of this period)
        'songhai-after-askia'                   => 'Q202687',    // Songhai Empire (shared with songhai-empire sibling; no sub-period item)
        'vijayanagara-under-krishnadevaraya'    => 'Q121235',    // Krishnadevaraya (the defining figure of this period)
        'maratha-empire-under-peshwas'          => 'Q83618',     // Maratha Empire (distinct from sibling's Q18456815 Maratha Confederacy)
        'mughal-decline-period'                 => 'Q125292294', // Decline of the Mughal Empire (dedicated article entity)
        'aztec-cultural-legacy'                 => 'Q12542',     // Aztec (civilization and people, broader than empire)
    ]],
    // Items with no appropriate Wikidata entity — excluded from identifier injection.
    ['type' => 'identifier_skip', 'ids' => [
        'thirty-years-war-states',          // Grouping of polities; Thirty Years' War (Q2487) is the conflict, not the states
        'huari-tiwanaku-interaction-sphere', // Archaeological concept; Wari Empire ≠ the interaction sphere
        'chimu-inca-war',                    // Conflict; no dedicated entity and Q581741 (Chimor) already used by chim-kingdom
    ]],
];
