<?php
/**
 * Look up Wikidata entity URIs and inject them as dcterms:identifier values.
 *
 * Usage:
 *   php dev/build-identifiers.php <dataset>           # look up and inject
 *   php dev/build-identifiers.php <dataset> --dry-run # report only, no writes
 *
 * Wikidata entity URIs (https://www.wikidata.org/entity/QXXX) serve as persistent
 * identifiers for the real-world subjects described by the items. Two automated
 * passes are used:
 *   1. SPARQL batch: match items by dcterms:title label (fast)
 *   2. Entity search fallback: wbsearchentities for unmatched items (slower, more precise)
 *
 * Add qid_overrides to dev/strategies/<dataset>.php to hard-code QIDs for items
 * where automatic matching returns the wrong entity.
 */
$args = array_slice($argv, 1);
$positional = array_values(array_filter($args, fn ($a) => !str_starts_with($a, '-')));

if (!$positional) {
    fwrite(STDERR, "Usage: php dev/build-identifiers.php <dataset> [--dry-run]\n");
    exit(1);
}

(new IdentifierBuilder($positional[0], in_array('--dry-run', $args)))->run();

class IdentifierBuilder
{
    private const SPARQL_DELAY  = 2.0;
    private const SEARCH_DELAY  = 0.5;
    private const ENTITY_BASE   = 'https://www.wikidata.org/entity/';

    private string  $dataset;
    private string  $dataFile;
    private bool    $dryRun;
    private string  $userAgent;
    private ?string $oauthToken     = null;
    private float   $lastSparqlTime = 0.0;
    private float   $lastSearchTime = 0.0;
    private bool    $rateLimited    = false;
    private ?array  $strategyPasses = null;

    public function __construct(string $dataset, bool $dryRun)
    {
        $this->dataset  = $dataset;
        $root = dirname(__DIR__);
        $this->dataFile = "$root/datasets/$dataset/$dataset.php";
        $this->dryRun   = $dryRun;

        $configFile = __DIR__ . '/config.php';
        $config = file_exists($configFile) ? require $configFile : [];
        $this->userAgent  = $config['wikidata_user_agent']
            ?? 'omeka-s/SampleData-module build-identifiers/1.0 (https://omeka.org)';
        $this->oauthToken = $config['wikidata_access_token'] ?? null;
    }

    public function run(): void
    {
        if (!file_exists($this->dataFile)) {
            fwrite(STDERR, "Dataset not found: {$this->dataFile}\n");
            exit(1);
        }

        $data    = require $this->dataFile;
        $items   = $data['items'] ?? [];
        $pending = array_values(array_filter($items, fn ($i) => empty($i['dcterms:identifier'])));

        $total = count($items);
        $done  = $total - count($pending);
        echo "Dataset: {$this->dataset} | Items: $total | Already done: $done | Pending: " . count($pending) . "\n";

        if (!$pending) {
            echo "Nothing to do.\n";
            return;
        }

        $identifierMap = $this->fetchIdentifiers($pending);

        if (!$identifierMap) {
            echo "Nothing to inject.\n";
            return;
        }

        if ($this->dryRun) {
            echo "\n(dry run — found " . count($identifierMap) . " identifiers)\n";
            foreach ($identifierMap as $id => $uri) {
                echo "  $id → $uri\n";
            }
            return;
        }

        foreach ($data['items'] as &$item) {
            $id = $item['id'] ?? null;
            if ($id && isset($identifierMap[$id])) {
                $item['dcterms:identifier'] = $identifierMap[$id];
            }
        }
        unset($item);

        $order = null;
        foreach ($this->loadStrategy() as $pass) {
            if (($pass['type'] ?? null) === 'field_order') {
                $order = $pass['fields'] ?? null;
                break;
            }
        }
        if ($order) {
            $data['items'] = array_map(fn ($i) => $this->reorderItem($i, $order), $data['items']);
        }

        file_put_contents($this->dataFile, "<?php\nreturn " . $this->phpVal($data, 0) . ";\n");
        echo "\nUpdated: {$this->dataFile}\n";
        echo "Injected: " . count($identifierMap) . "\n";
    }

    private function fetchIdentifiers(array $pending): array
    {
        $identifierMap = [];
        $skip         = [];
        $suffixes     = [];
        $useAltLabels = false;

        foreach ($this->loadStrategy() as $pass) {
            $type = $pass['type'] ?? null;
            if (isset($pass['qid_overrides']) && !isset($pass['property'])) {
                foreach ($pending as $item) {
                    $id = $item['id'] ?? null;
                    if ($id && isset($pass['qid_overrides'][$id])) {
                        $qid = $pass['qid_overrides'][$id];
                        if ($qid !== null) {
                            $identifierMap[$id] = self::ENTITY_BASE . $qid;
                        }
                    }
                }
            } elseif ($type === 'identifier_skip') {
                $skip = array_merge($skip, $pass['ids'] ?? []);
            } elseif ($type === 'strip_suffixes') {
                $suffixes = array_merge($suffixes, $pass['suffixes'] ?? []);
            } elseif ($type === 'use_alt_labels') {
                $useAltLabels = true;
            }
        }
        if ($identifierMap) {
            echo "Pass 0: QID overrides — " . count($identifierMap) . " applied\n";
        }
        $pending = array_values(array_filter(
            $pending,
            fn ($i) => !isset($identifierMap[$i['id'] ?? '']) && !in_array($i['id'] ?? '', $skip)
        ));

        // Pass 1: SPARQL batch by dcterms:title label
        if ($pending && !$this->rateLimited) {
            echo "Pass 1: SPARQL label match (" . count($pending) . " pending)\n";
            $found = $this->fetchByLabel($pending, $suffixes, $useAltLabels);
            $identifierMap += $found;
            $pending = array_values(array_filter($pending, fn ($i) => !isset($identifierMap[$i['id'] ?? ''])));
            echo "  Matched: " . count($found) . "\n";
        }

        // Pass 2: Entity search fallback
        if ($pending && !$this->rateLimited) {
            echo "Pass 2: entity search fallback (" . count($pending) . " pending)\n";
            foreach ($pending as $item) {
                if ($this->rateLimited) {
                    break;
                }
                $id    = $item['id'] ?? null;
                $title = $item['dcterms:title'] ?? '';
                if (!$id || $title === '') {
                    continue;
                }
                $qid = $this->entitySearch($title);
                if ($qid) {
                    $identifierMap[$id] = self::ENTITY_BASE . $qid;
                    echo "  Found: $title → $qid\n";
                } else {
                    echo "  Not found: $title\n";
                }
            }
        }

        $remaining = array_filter($pending, fn ($i) => !isset($identifierMap[$i['id'] ?? '']));
        if ($remaining) {
            echo "\nNo identifier found for " . count($remaining) . " item(s):\n";
            foreach ($remaining as $item) {
                echo "  {$item['id']}" . (isset($item['dcterms:title']) ? " ({$item['dcterms:title']})" : '') . "\n";
            }
        }

        return $identifierMap;
    }

    private function fetchByLabel(array $items, array $suffixes = [], bool $useAltLabels = false): array
    {
        $labelIndex = [];
        $lines      = [];

        foreach ($items as $item) {
            $label = trim($item['dcterms:title'] ?? '');
            if ($label === '') {
                continue;
            }
            // Register a label variant and its lowercase form for SPARQL matching.
            // Wikidata sometimes uses lowercase for common nouns (e.g. "Olmec civilization").
            $addVariant = function (string $lbl) use (&$lines, &$labelIndex, $item): void {
                $lines[] = '("' . $this->sparqlStr($lbl) . '"@en)';
                $labelIndex[$lbl][] = $item['id'];
                $lower = mb_strtolower($lbl, 'UTF-8');
                if ($lower !== $lbl) {
                    $lines[] = '("' . $this->sparqlStr($lower) . '"@en)';
                    $labelIndex[$lower][] = $item['id'];
                }
            };
            $addVariant($label);
            // Paren removal and suffix removal are each applied independently (not chained)
            // to avoid over-stripping — "French Empire (Napoleon)" yields only "French Empire".
            foreach ($this->stripTitleSuffix($label, $suffixes) as $stripped) {
                $addVariant($stripped);
            }
        }

        if (!$lines) {
            return [];
        }

        $found = [];

        // Batch up to 500 labels per query to avoid SPARQL timeouts.
        foreach (array_chunk(array_unique($lines), 500) as $chunk) {
            if ($this->rateLimited) {
                break;
            }
            $valuesClause = implode(' ', $chunk);
            // ORDER BY QID integer ensures lowest (most canonical) QID wins per label.
            $labelClause = $useAltLabels
                // Also match skos:altLabel — catches entities whose alias matches our title
                // (e.g. "Chimú Kingdom" is an alias of the "Chimor" entity). Opt-in only:
                // short/common titles can match unrelated entities via alt-labels.
                ? "  {\n"
                . "    ?item rdfs:label ?label .\n"
                . "    FILTER(langMatches(lang(?label), \"en\"))\n"
                . "  } UNION {\n"
                . "    ?item skos:altLabel ?label .\n"
                . "    FILTER(langMatches(lang(?label), \"en\"))\n"
                . "  }\n"
                : "  ?item rdfs:label ?label .\n"
                . "  FILTER(langMatches(lang(?label), \"en\"))\n";
            $query = "SELECT DISTINCT ?item ?label WHERE {\n"
                   . "  VALUES (?label) { $valuesClause }\n"
                   . $labelClause
                   . "  FILTER(STRSTARTS(STR(?item), 'http://www.wikidata.org/entity/Q'))\n"
                   . "  FILTER NOT EXISTS { ?item owl:deprecated true }\n"
                   . "} ORDER BY ASC(xsd:integer(STRAFTER(STR(?item), 'entity/Q')))";

            $rows = $this->sparql($query);
            echo "  SPARQL returned " . count($rows) . " rows\n";

            foreach ($rows as $row) {
                $uri   = $this->normalizeUri($row['item']['value'] ?? null);
                $label = $row['label']['value'] ?? null;
                if (!$uri || !$label) {
                    continue;
                }
                foreach ($labelIndex[$label] ?? [] as $id) {
                    $found[$id] ??= $uri;
                }
            }
        }

        return $found;
    }

    private function normalizeUri(?string $uri): ?string
    {
        if (!$uri) {
            return null;
        }
        // SPARQL endpoint returns http://www.wikidata.org/entity/; normalize to https://
        return str_replace('http://www.wikidata.org/', 'https://www.wikidata.org/', $uri);
    }

    private function entitySearch(string $query): ?string
    {
        $this->throttle($this->lastSearchTime, self::SEARCH_DELAY);

        $url = 'https://www.wikidata.org/w/api.php?' . http_build_query([
            'action'   => 'wbsearchentities',
            'search'   => $query,
            'language' => 'en',
            'type'     => 'item',
            'limit'    => 1,
            'format'   => 'json',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200 || !$body) {
            return null;
        }
        $result = json_decode($body, true);
        return $result['search'][0]['id'] ?? null;
    }

    /**
     * Return title variants for broader SPARQL matching. Transformations are applied
     * independently (not chained) to avoid over-stripping.
     *
     * @return string[]
     */
    private function stripTitleSuffix(string $label, array $suffixes): array
    {
        $variants = [];

        // Strip trailing parenthetical qualifier: "French Empire (Napoleon)" → "French Empire"
        $noParens = trim(preg_replace('/\s*\([^)]+\)\s*$/', '', $label));
        if ($noParens !== $label && strlen($noParens) >= 3) {
            $variants[] = $noParens;
        }

        // Strip trailing type suffix from the ORIGINAL label only — never from the
        // paren-stripped result, which would risk double-stripping (e.g. "French").
        // Suffixes are dataset-specific and provided via the strip_suffixes strategy pass.
        foreach ($suffixes as $suffix) {
            if (str_ends_with($label, " $suffix")) {
                $candidate = rtrim(substr($label, 0, -strlen(" $suffix")));
                if (strlen($candidate) >= 3) {
                    $variants[] = $candidate;
                }
                break;
            }
        }

        return $variants;
    }

    private function loadStrategy(): array
    {
        if ($this->strategyPasses !== null) {
            return $this->strategyPasses;
        }
        $strategyFile = __DIR__ . "/strategies/{$this->dataset}.php";
        return $this->strategyPasses = file_exists($strategyFile) ? require $strategyFile : [];
    }

    private function sparql(string $query): array
    {
        $this->throttle($this->lastSparqlTime, self::SPARQL_DELAY);

        $retryAfter = null;
        $headers    = ['Accept: application/sparql-results+json'];
        if ($this->oauthToken) {
            $headers[] = 'Authorization: Bearer ' . $this->oauthToken;
        }
        $ch = curl_init('https://query.wikidata.org/sparql');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['query' => $query]),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HEADERFUNCTION => $this->retryAfterClosure($retryAfter),
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status === 429) {
            $hint = $retryAfter ? " (Retry-After: {$retryAfter}s)" : '';
            fwrite(STDERR, "SPARQL rate limited{$hint} — wait and re-run.\n");
            $this->rateLimited = true;
            return [];
        }
        if ($status !== 200 || !$body) {
            fwrite(STDERR, "SPARQL error $status\n");
            return [];
        }
        $result = json_decode($body, true);
        return $result['results']['bindings'] ?? [];
    }

    private function retryAfterClosure(?int &$retryAfter): \Closure
    {
        return function ($ch, $header) use (&$retryAfter) {
            if (stripos($header, 'Retry-After:') === 0) {
                $retryAfter = (int) trim(substr($header, 12));
            }
            return strlen($header);
        };
    }

    private function throttle(float &$last, float $delay): void
    {
        $now = microtime(true);
        if ($now - $last < $delay) {
            usleep((int) (($delay - ($now - $last)) * 1_000_000));
        }
        $last = microtime(true);
    }

    private function sparqlStr(string $s): string
    {
        $s = addcslashes($s, '"\\');
        return str_replace(["\n", "\r"], ['\\n', '\\r'], $s);
    }

    private function reorderItem(array $item, array $order): array
    {
        $out = [];
        foreach ($order as $key) {
            if (array_key_exists($key, $item)) {
                $out[$key] = $item[$key];
            }
        }
        foreach ($item as $key => $val) {
            if (!array_key_exists($key, $out)) {
                $out[$key] = $val;
            }
        }
        return $out;
    }

    private function phpVal(mixed $v, int $depth): string
    {
        $pad  = str_repeat('    ', $depth);
        $ipad = str_repeat('    ', $depth + 1);

        if (is_null($v))  return 'null';
        if (is_bool($v))  return $v ? 'true' : 'false';
        if (is_int($v))   return (string) $v;
        if (is_float($v)) {
            $native = (string) $v;
            $s = rtrim(sprintf('%.10F', $v), '0');
            $s = rtrim($s, '.');
            if (!str_contains($s, '.')) {
                $s .= '.0';
            }
            return strlen($native) <= strlen($s) ? $native : $s;
        }
        if (is_string($v)) {
            $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $v);
            return "'$escaped'";
        }
        if (is_array($v)) {
            if (empty($v)) {
                return '[]';
            }
            $isAssoc = !array_is_list($v);
            $lines   = [];
            foreach ($v as $k => $val) {
                $serialized = $this->phpVal($val, $depth + 1);
                $lines[] = $isAssoc
                    ? $ipad . $this->phpVal($k, $depth + 1) . ' => ' . $serialized
                    : $ipad . $serialized;
            }
            return "[\n" . implode(",\n", $lines) . ",\n{$pad}]";
        }
        return var_export($v, true);
    }
}
