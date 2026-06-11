<?php
return [
    ['type' => 'field_order', 'fields' => [
        'id', 'class', 'sets', 'relations', 'dcterms:identifier',
        'dcterms:title', 'dcterms:alternative', 'sample-data:knownFor', 'dcterms:description',
        'dcterms:subject', 'sample-data:nationality', 'dcterms:language', 'dcterms:temporal',
        'sample-data:birthDate', 'sample-data:deathDate', 'sample-data:birthPlace',
        'sample-data:deathPlace', 'map_coordinates', 'media',
    ]],
    ['type' => 'override', 'qid_overrides' => [
        'queen-nzinga'  => 'Q467650',  // Wikidata label: "Nzingha Mbande"
        'amina-of-zaria' => 'Q2843390', // Wikidata label: "Queen Amina"
    ]],
];
