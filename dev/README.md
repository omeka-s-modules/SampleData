# dev/

Build tools for the SampleData module. None of these files are shipped with the module.

## Contents

| Path | Description |
|------|-------------|
| `build-identifiers.php` | Looks up Wikidata entity URIs and injects them as `dcterms:identifier` values |
| `build-media.php` | Fetches and downloads media files for a dataset from Wikidata/Wikimedia Commons |
| `config.php` | Local config — Wikimedia API credentials (**gitignored**) |
| `config.php.dist` | Template for `config.php` |
| `strategies/` | Per-dataset overrides for `build-identifiers.php` and `build-media.php` |

---

## Setup

Copy the config template and fill in your Wikimedia OAuth credentials:

```
cp dev/config.php.dist dev/config.php
```

Register an owner-only OAuth 2.0 consumer at
`https://meta.wikimedia.org/wiki/Special:OAuthConsumerRegistration/propose`
to get an access token. The user agent string should identify your app,
e.g. `YourName/SampleData-build (your@email.example)`.

`config.php` is gitignored. Do not commit it.

---

## build-identifiers.php

Looks up a Wikidata entity URI for each item in a dataset that does not already
have a `dcterms:identifier` value, and injects it into the dataset PHP file.
The URI format is `https://www.wikidata.org/entity/QXXX`.

```
php dev/build-identifiers.php <dataset>           # look up and inject
php dev/build-identifiers.php <dataset> --dry-run # report only, no writes
```

Re-running is safe — only items without an existing `dcterms:identifier` are
processed.

### How it searches

Each item goes through a series of passes until a match is found:

1. **QID overrides** — hard-coded QIDs from the dataset's strategy file
   (see **Strategies** below).
2. **SPARQL batch** — matches items by `dcterms:title` label against Wikidata
   `rdfs:label`. Also tries lowercase variants and paren-stripped variants
   (e.g. "Madame X (Madame Pierre Gautreau)" → "Madame X"). If the dataset
   strategy file includes a `strip_suffixes` pass, suffix-stripped variants are
   tried as well (e.g. "Olmec Civilization" → "Olmec").
3. **Entity search fallback** — calls `wbsearchentities` for any items still
   unmatched.

### Rate limits

SPARQL requests are spaced 2 seconds apart; entity searches 0.5 seconds. If a
429 is returned the script stops and reports how far it got — re-run to
continue from where it left off (already-injected items are skipped).

---

## build-media.php

Finds a Wikimedia Commons image for each item in a dataset that does not already
have one, downloads it to `datasets/<dataset>/media/`, and injects the `'media'`
key into the dataset PHP file.

```
php dev/build-media.php <dataset>           # fetch + download
php dev/build-media.php <dataset> --dry-run # report URLs only, no writes
```

The script processes only items that have no `media` key or whose file is missing
from the `media/` directory. Re-running is safe.

### How it searches

Each item goes through a series of passes until a match is found:

1. **Label pass (title + creator)** — SPARQL query matching both `dcterms:title` and
   `dcterms:creator` against Wikidata `rdfs:label` via P18 (image) and P170 (creator).
2. **Label pass (title only)** — same query with only `dcterms:title`.
3. **Label pass (title, article stripped)** — strips leading articles
   ("The", "La", "Le", etc.) before matching.
4. **Entity search** — calls `wbsearchentities` to find each item's Wikidata QID, then
   batch-fetches P18.
5. **Commons search (title + creator)** — full-text search in Wikimedia Commons
   File namespace.
6. **Commons search (title only)** — same search with only `dcterms:title`.

Strategy overrides (see below) run before these default passes.

### Rate limits

The script respects API rate limits with automatic backoff. SPARQL requests are
spaced 2 seconds apart; entity and Commons searches 0.5 seconds. If a 429 is
returned the script sleeps for the Retry-After duration and continues.

---

## Strategies

Each dataset can have a strategy file at `dev/strategies/<dataset>.php`.
It returns an array of passes that extend or override the default search
behaviour.

Passes with `'type' => 'override'` are applied before the default search
passes. All other pass types either configure search behaviour or control
output, and are applied at setup time.

### identifier_skip

Mark items as having no Wikidata entity, so `build-identifiers.php` does not
attempt to match them. Without this, a short or common-word title may match an
unrelated entity.

```php
['type' => 'identifier_skip', 'ids' => [
    'item-id', // reason: no Wikidata entity; title matches unrelated entities
]],
```

This pass is ignored by `build-media.php`.

### use_alt_labels

Tell `build-identifiers.php` to also match items against Wikidata `skos:altLabel`
values, in addition to the default `rdfs:label` match. Without this pass, only
`rdfs:label` is searched (safe default).

```php
['type' => 'use_alt_labels'],
```

Useful when dataset titles differ from Wikidata's canonical label but appear as
an alias (e.g. "Chimú Kingdom" is an alias of the "Chimor" entity). Avoid on
datasets with short or common-word titles — those can match unrelated entities
that happen to share an alias. This pass is ignored by `build-media.php`.

### strip_suffixes

Tell `build-identifiers.php` to also search Wikidata using the title with a
trailing type suffix removed — e.g. "Olmec Civilization" → also try "Olmec".
Without this pass, suffix stripping is disabled (safe default for datasets
where titles don't follow the `<name> <type>` pattern).

```php
['type' => 'strip_suffixes', 'suffixes' => [
    'Civilization', 'Kingdom', 'Empire', 'Dynasty', 'Republic', // ...
]],
```

Each suffix is checked case-sensitively against the end of the title. Only one
suffix is stripped per title, and it is never combined with paren-stripping on
the same variant, to prevent over-stripping. This pass is ignored by
`build-media.php`.

### field_order

Control the key order when `build-identifiers.php` rewrites a dataset file.
Without this, newly injected keys appear at the end of each item array.

```php
['type' => 'field_order', 'fields' => [
    'id', 'class', 'sets', 'relations', 'dcterms:identifier',
    'dcterms:title', 'dcterms:description', 'media',
]],
```

Keys not listed are appended after the listed ones, preserving their original
relative order. This pass is ignored by `build-media.php`.

### commons_file_overrides

Hardcode a specific Commons filename for an item:

```php
['type' => 'override', 'commons_file_overrides' => [
    'item-id' => 'Exact Commons Filename.jpg',
]]
```

Use when the automated passes would find the wrong image — e.g. an item whose
title matches many unrelated Commons files.

This pass is ignored by `build-identifiers.php`.

### qid_overrides

Hardcode a Wikidata QID for an item. The `property` key controls which script
uses the override:

```php
// Used by build-media.php (P18 = image property)
['type' => 'override', 'qid_overrides' => [
    'item-id' => 'Q123456',
], 'property' => 'P18'],

// Used by build-identifiers.php (no property key)
['type' => 'override', 'qid_overrides' => [
    'item-id' => 'Q123456',
]],
```

Use when automatic matching returns the wrong entity — e.g. the dataset title
differs from the Wikidata label, or a low-QID entity with the same label
shadows the intended one.

### skip

Mark items as having no available image:

```php
['type' => 'override', 'skip' => [
    'item-id', // reason: no free image on Commons
]],
```

Skipped items are removed from the pending list and not retried.

This pass is ignored by `build-identifiers.php`.

---

## Building a dataset

### 1. Create the directory

```
datasets/<name>/
datasets/<name>/<name>.php   # data file
datasets/<name>/media/       # created automatically by build-media.php
```

### 2. Write the data file

`datasets/<name>/<name>.php` must return an associative array with three keys:
`item_sets`, `resource_template`, and `items`.

```php
<?php
return [

    // -------------------------------------------------------------------------
    // Item sets
    // Keys are slugs used in item 'sets' arrays. The 'main' key is the primary
    // set and must always be present — the admin UI uses it for the browse link.
    // -------------------------------------------------------------------------
    'item_sets' => [
        'main' => [
            'dcterms:title'       => 'My Dataset',
            'dcterms:description' => 'A short description.',
        ],
        'subcategory-one' => [
            'dcterms:title'       => 'Subcategory One',
            'dcterms:description' => 'Items in this subcategory.',
        ],
    ],

    // -------------------------------------------------------------------------
    // Resource template
    // The label should use the "Sample Data: " prefix to avoid conflicts with
    // user-created templates. Properties not found in any installed vocabulary
    // are silently skipped on import.
    // -------------------------------------------------------------------------
    'resource_template' => [
        'label' => 'Sample Data: My Item',
        'properties' => [
            ['term' => 'dcterms:title'],
            ['term' => 'dcterms:description'],
            ['term' => 'dcterms:date', 'data_type' => ['numeric:timestamp']],
            // alternate_label overrides the property label in the form:
            ['term' => 'dcterms:relation', 'data_type' => ['resource'], 'alternate_label' => 'Related Items'],
            // sample-data vocabulary terms:
            ['term' => 'sample-data:knownFor'],
        ],
    ],

    // -------------------------------------------------------------------------
    // Items
    // -------------------------------------------------------------------------
    'items' => [
        [
            // Required. Kebab-case slug, unique within the dataset. Used for
            // inter-item relation links and media file naming.
            'id' => 'my-item',

            // Resource class from the sample-data vocabulary (optional).
            // See "Vocabulary" section below for available classes.
            'class' => 'sample-data:Person',

            // Item set keys from the item_sets array above.
            'sets' => ['main', 'subcategory-one'],

            // IDs of related items (dcterms:relation). These are linked in a
            // second pass after all items are created.
            'relations' => ['other-item-id'],

            // Map geometry. Requires the Mapping module; skipped silently when inactive.
            // The geometry type is inferred from the coordinate structure:
            //   point      — [lng, lat]
            //   linestring — [[lng, lat], [lng, lat], ...]
            //   polygon    — [[[lng, lat], ...], ...]  (first ring exterior, additional rings holes)
            'map_coordinates' => [-0.1276, 51.5072],

            // Bounding box for the map view: 'west,south,east,north'.
            // Only used when map_coordinates is also set.
            'map_bounds' => '-1.5,51.0,0.5,52.0',

            // Any installed vocabulary term can be used here — the importer
            // resolves term strings at runtime, so foaf:name, schema:*, etc.
            // all work as long as the vocabulary is installed. Terms from
            // missing vocabularies are silently skipped. Values can be strings
            // or arrays of strings.
            'dcterms:title'       => 'My Item',
            'dcterms:description' => 'A longer description of this item.',
            'dcterms:date'        => '1850',         // plain text or ISO 8601
            'dcterms:subject'     => ['Tag One', 'Tag Two'],

            // Media filename relative to datasets/<name>/media/.
            // Populated automatically by build-media.php.
            'media' => 'my-item.jpg',
        ],
    ],
];
```

#### Numeric data types

When the `NumericDataTypes` module is active, values for properties with
`data_type` set are stored as typed numeric values. When it is inactive they
are stored as plain text. Supported types: `numeric:timestamp`, `numeric:duration`,
`numeric:interval`, `numeric:integer`.

Value formats:
- `numeric:timestamp` — ISO 8601 year or date: `'1850'`, `'-500'`, `'1850-06-15'`
- `numeric:duration` — ISO 8601 duration: `'P250Y'` (250 years), `'P1Y6M'`
- `numeric:interval` — ISO 8601 interval: `'-500/-323'`
- `numeric:integer` — plain integer string: `'150000'`

### 3. Register in module.config.php

Add an entry to the `sample_data.datasets` array in `config/module.config.php`:

```php
'my-dataset' => [
    'label'       => 'My Dataset',
    'description' => 'One or two sentences describing what the dataset contains.',
    'item_count'  => 50,
    'set_count'   => 2,
],
```

`item_count` and `set_count` are displayed on the module admin page before import.

### 4. Inject identifiers

```
php dev/build-identifiers.php my-dataset --dry-run   # review matches before writing
php dev/build-identifiers.php my-dataset             # inject dcterms:identifier values
```

### 5. Fetch media

```
php dev/build-media.php my-dataset --dry-run   # review URLs before downloading
php dev/build-media.php my-dataset             # download and inject media keys
```

Create a strategy file at `dev/strategies/my-dataset.php` for any items either
script cannot match correctly (see **Strategies** above).

---

## Vocabulary

The `sample-data` vocabulary (`https://omeka.org/s/vocabs/sample-data#`) is
installed by the module. Use its terms as `class` values and property terms in
dataset files.

### Classes

| Term | Label |
|------|-------|
| `sample-data:Empire` | Empire |
| `sample-data:Kingdom` | Kingdom |
| `sample-data:Dynasty` | Dynasty |
| `sample-data:City-state` | City-state |
| `sample-data:Confederation` | Confederation |
| `sample-data:CulturalPeriod` | Cultural Period |
| `sample-data:Republic` | Republic |
| `sample-data:Painting` | Painting |
| `sample-data:Sculpture` | Sculpture |
| `sample-data:WorkOnPaper` | Work on Paper |
| `sample-data:Manuscript` | Manuscript |
| `sample-data:Letter` | Letter |
| `sample-data:Diary` | Diary |
| `sample-data:Memorandum` | Memorandum |
| `sample-data:Report` | Report |
| `sample-data:Newspaper` | Newspaper |
| `sample-data:Person` | Person |

### Properties

| Term | Label | Used in |
|------|-------|---------|
| `sample-data:movement` | Movement | Artworks |
| `sample-data:peakDate` | Peak Date | Civilizations |
| `sample-data:area` | Area | Civilizations |
| `sample-data:birthDate` | Birth Date | People |
| `sample-data:deathDate` | Death Date | People |
| `sample-data:birthPlace` | Birth Place | People |
| `sample-data:deathPlace` | Death Place | People |
| `sample-data:nationality` | Nationality | People |
| `sample-data:knownFor` | Known For | People |
