<?php
/**
 * Civilizations dataset media strategy.
 *
 * qid_overrides: hardcoded Wikidata QIDs for items the entity search cannot find
 * (titles differ from Wikidata labels, ambiguous matches, etc.).
 */
return [
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
];
