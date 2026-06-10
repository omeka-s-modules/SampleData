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
    ['type' => 'override', 'qid_overrides' => [
        'wanderer-above-the-sea-of-fog' => 'Q311243',
        'moulin-de-la-galette' => 'Q683274',
        'the-discobolus' => 'Q133732',
        'bust-of-nefertiti' => 'Q582172',
        'self-portrait-two-circles' => 'Q2872725',
        'ognissanti-madonna' => 'Q2016193',
        'doryphoros' => 'Q1136305',
    ], 'property' => 'P18'],
];
