<?php
/**
 * Artworks dataset media strategy.
 *
 * commons_file_overrides: hardcoded Commons filenames for items the search passes
 * cannot auto-match (unusual titles, non-English labels, obscure entries).
 *
 * qid_overrides: hardcoded Wikidata QIDs for items the entity search cannot find
 * (common words in titles, ambiguous matches, or entities with no English label).
 *
 * skip: items for which no free image is available anywhere.
 */
return [
    ['type' => 'field_order', 'fields' => [
        'id', 'class', 'sets', 'relations', 'dcterms:identifier',
        'dcterms:title', 'dcterms:creator', 'dcterms:created', 'dcterms:description',
        'dcterms:subject', 'dcterms:medium', 'dcterms:publisher', 'sample-data:movement',
        'map_coordinates', 'media',
    ]],
    ['type' => 'override', 'commons_file_overrides' => [
        'divine-comedy-manuscript' => 'Dante Pd10 BL Yates Thompson 36 f139.jpg',
        'book-of-hours-catherine-of-cleves' => 'Gathering of the Manna - Hours of Catherine of Cleves - MS M. 917-945 137v - Morgan Library New York, around 1440.jpg',
        'self-portrait-at-28' => 'Albrecht Dürer - 1500 self-portrait (High resolution and detail).jpg',
        'job-mucha' => 'Mucha-job.jpg',
        'woman-with-the-hat' => 'Matisse-Woman-with-a-Hat.jpg',
        'open-window-collioure' => 'Open Window, Collioure, 1905 - Henri Matisse.jpg',
        'stone-city-iowa' => 'Stone City Iowa 1930 Grant Wood.jpg',
        'perseus-triumphant' => 'Metropolitan canova perseus medusa 01.JPG',
        'mask-of-tutankhamun' => 'Mask of Tutankhamun in 2025.jpg',
        'red-fuji' => 'Katsushika Hokusai, published by Nishimuraya Yohachi (Eijudō) - Fine Wind, Clear Weather (Gaifū kaisei), also known as Red Fuji, from the series Thirty-six Views o... - Google Art Project - Cropped.jpg',
        'walking-man' => "L'exposition \"l'Homme qui marche', Institut Giacometti, Paris, 11 octobre 2020 01.jpg",
    ]],
    // Items for which no free Commons image exists — excluded from media sourcing.
    ['type' => 'override', 'skip' => [
        'nude-descending-staircase', // Duchamp 1912 painting not uploaded to Commons
    ]],
    // Items with no Wikidata entity — excluded from identifier injection to prevent
    // false matches on short or common-word titles.
    ['type' => 'identifier_skip', 'ids' => [
        'job-mucha', // Mucha's "Job" cigarette paper poster has no Wikidata item; title matches unrelated entities
    ]],
    ['type' => 'override', 'qid_overrides' => [
        'wanderer-above-the-sea-of-fog' => 'Q311243',
        'moulin-de-la-galette' => 'Q683274',
        'the-discobolus' => 'Q133732',
        'bust-of-nefertiti' => 'Q582172',
        'self-portrait-two-circles' => 'Q2872725',
        'ognissanti-madonna' => 'Q2016193',
        'doryphoros' => 'Q1136305',
    ], 'property' => 'P18'],
    // QID overrides for dcterms:identifier injection (build-identifiers.php).
    // These items were not matched by the automatic SPARQL/entity-search passes,
    // because the dataset title differs from Wikidata's English label.
    ['type' => 'override', 'qid_overrides' => [
        // Wikidata: "A Sunday Afternoon on the Island of La Grande Jatte"
        'a-sunday-on-la-grande-jatte'    => 'Q1044742',
        // Wikidata: "Ghent Altarpiece" (dataset: "The Ghent Altarpiece")
        'ghent-altarpiece'               => 'Q734834',
        // Wikidata: "Mérode Altarpiece" (dataset: "The Mérode Altarpiece")
        'merode-altarpiece'              => 'Q285392',
        'la-grande-odalisque'            => 'Q1978815',
        // Wikidata: "Luncheon of the Boating Party" (dataset: "The Luncheon of...")
        'luncheon-of-the-boating-party'  => 'Q1167907',
        // Wikidata: "Self-Portrait with Fur-Trimmed Robe" (dataset: "Self-Portrait at 28")
        'self-portrait-at-28'            => 'Q2546309',
        // Wikidata: "Wilton Diptych" (dataset: "The Wilton Diptych")
        'wilton-diptych'                 => 'Q1813497',
        // Wikidata: "Studies for the Libyan Sibyl"
        'study-for-the-libyan-sibyl'     => 'Q29384857',
        // Wikidata: "Madame X" (dataset: "Madame X (Madame Pierre Gautreau)")
        'madame-x'                       => 'Q411578',
        // Wikidata: "Arrangement in Grey and Black No. 1"
        'whistlers-mother'               => 'Q687182',
        // Wikidata: "Composition II in Red, Blue, and Yellow"
        'composition-red-blue-yellow'    => 'Q19609193',
        'marilyn-diptych'                => 'Q573949',
        'stone-city-iowa'                => 'Q30041900',
        // Wikidata: "L'Homme qui marche I" (dataset: "Walking Man I")
        'walking-man'                    => 'Q706964',
        // Wikidata: "Number 31" (dataset: "No. 31")
        'no-31-pollock'                  => 'Q112675413',
        'no-61-rust-and-blue'            => 'Q4540018',
        'self-portrait-dix'              => 'Q24953623',
        'woman-with-the-hat'             => 'Q1437492',
        // Wikidata: "The Harlequin's Carnival" (dataset: "Harlequin's Carnival")
        'harlekins-carnival'             => 'Q782079',
        'just-what-is-it'                => 'Q3190307',
        // Wikidata: "The Open Window" / fr: "Fenêtre ouverte, Collioure"
        'open-window-collioure'          => 'Q3745698',
        // Wikidata: "Belles Heures of Jean de France, Duc de Berry"
        'belles-heures'                  => 'Q1959777',
        // Wikidata: "Fine Wind, Clear Morning" (dataset: "Fine Wind, Clear Morning (Red Fuji)")
        'red-fuji'                       => 'Q3565037',
        // Wikidata: "Sudden Shower over Shin-Ohashi bridge and Atake"
        'sudden-shower-shin-ohashi'      => 'Q5826309',
        // Wikidata: "Plum Park in Kameido" (dataset: "Plum Estate, Kameido")
        'plum-estate'                    => 'Q21127606',
        'hours-of-jeanne-devreux'        => 'Q1516907',
        // Wikidata: "Twittering Machine" (dataset has German subtitle in parens)
        'twittering-machine'             => 'Q3210239',
        // Wikidata: "Young Hare" (dataset: "Young Hare (Feldhase)")
        'young-hare'                     => 'Q699388',
        'golden-haggadah'                => 'Q17629688',
        'perseus-triumphant'             => 'Q29383614',
        'mask-of-tutankhamun'            => 'Q9048095',
        // Wikidata: "Yates Thompson MS 36"
        'divine-comedy-manuscript'       => 'Q48813347',

        // Corrections for wrong automatic SPARQL matches (lowest QID was a non-artwork entity).
        // Wikidata: "L'Homme qui marche I" sculpture (auto-matched Q706964 ✓, but listed here for clarity)
        'the-kiss-klimt'                 => 'Q698487',   // was: Q464761 (1896 film)
        'the-kiss-rodin'                 => 'Q2418237',  // was: Q464761 (1896 film)
        'rhinoceros'                     => 'Q748518',   // was: Q134657 (genus of mammals)
        'david'                          => 'Q179900',   // was: Q41370 (King of Israel)
        'olympia'                        => 'Q737062',   // was: Q38888 (town in Greece)
        'flag-johns'                     => 'Q5456612',  // was: Q297187 (disambiguation page)
        'isle-of-the-dead'               => 'Q669994',   // was: Q629711 (Rachmaninoff tone poem)
        'water-lilies'                   => 'Q1189907',  // was: Q93512 (2007 French film)
        'the-night-watch'                => 'Q219831',   // was: Q38187 (2006 novel)
        'the-dream-marc'                 => 'Q18685517', // was: Q548141 (Rousseau's The Dream)
        'la-joie-de-vivre'               => 'Q2538580',  // was: Q1212773 (Zola novel)
        'four-horsemen-of-the-apocalypse'=> 'Q5980742',  // was: Q58155 (biblical concept)
        'three-crosses'                  => 'Q1218084',  // was: Q83238 (Lithuanian monument)
        'gismonda-mucha'                 => 'Q60591741', // was: Q594492 (opera by Février)
        'la-nature-mucha'                => 'Q48755437', // was: Q3211059 (French science magazine)
        'three-musicians'                => 'Q30332241', // was: Q389198 (Velázquez's Three Musicians)

        // These were found by entity search but entity search is rate-limited at run time.
        // Adding as overrides for reliability.
        'the-arnolfini-portrait'         => 'Q220859',
        'sistine-chapel-ceiling'         => 'Q844675',
        'a-bar-at-the-folies-bergere'    => 'Q1245354',
        'tres-riches-heures'             => 'Q211062',
        'tres-belles-heures-de-notre-dame' => 'Q2308135',
    ]],
];
