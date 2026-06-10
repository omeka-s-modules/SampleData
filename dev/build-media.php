<?php
/**
 * Fetch and download missing media files for a dataset from Wikidata/Wikimedia Commons.
 * Injects 'media' keys into the dataset PHP file for each downloaded image.
 *
 * Usage:
 *   php dev/build-media.php <dataset>           # fetch + download missing files
 *   php dev/build-media.php <dataset> --dry-run # report URLs only, no downloads or writes
 */
$args = array_slice($argv, 1);
$positional = array_values(array_filter($args, fn ($a) => !str_starts_with($a, '-')));

if (!$positional) {
    fwrite(STDERR, "Usage: php dev/build-media.php <dataset> [--dry-run]\n");
    exit(1);
}

(new MediaBuilder($positional[0], in_array('--dry-run', $args)))->run();

class MediaBuilder
{
    /**
     * Default passes run for every dataset. Pass types:
     *
     * Label pass: batch-match items against Wikidata rdfs:label via SPARQL VALUES.
     *   ['property' => 'P18', 'fields' => ['dcterms:title'], 'normalize' => [...]]
     *
     * Search pass: find each item's QID via wbsearchentities, then batch-fetch by QID.
     * Handles case mismatches and alternate titles that the label pass can't resolve.
     *   ['search' => 'dcterms:title', 'property' => 'P18']
     *
     * Commons search pass: search Wikimedia Commons File namespace by field values.
     *   ['commons_search' => ['dcterms:title', 'dcterms:creator']]
     *
     * Dataset-specific passes are loaded from dev/strategies/<dataset>.php.
     * Passes marked 'type' => 'override' run before these default passes; all
     * others run after.
     *
     * Override pass types (add 'type' => 'override' to run before default passes):
     *   ['type' => 'override', 'commons_file_overrides' => ['item-id' => 'Commons filename.jpg', ...]]
     *   ['type' => 'override', 'qid_overrides' => ['item-id' => 'Q12345', ...], 'property' => 'P18']
     *   ['type' => 'override', 'skip' => ['item-id', ...]]
     */
    private const DEFAULT_PASSES = [
        ['property' => 'P18', 'fields' => ['dcterms:title', 'dcterms:creator'],
            'normalize' => ['dcterms:creator' => 'normalizeCreator'], 'secondary_via' => 'P170'],
        ['property' => 'P18', 'fields' => ['dcterms:title']],
        ['property' => 'P18', 'fields' => ['dcterms:title'],
            'normalize' => ['dcterms:title' => 'stripLeadingArticle']],
        ['search' => 'dcterms:title', 'property' => 'P18'],
        ['commons_search' => ['dcterms:title', 'dcterms:creator']],
        ['commons_search' => ['dcterms:title']],
    ];

    private const SPARQL_DELAY = 2.0;
    private const SEARCH_DELAY = 0.5;
    private const DOWNLOAD_DELAY = 0.5;
    private const IMAGE_WIDTH = 400;
    private const BAD_EXTENSIONS = ['tif', 'tiff', 'ogg', 'ogv', 'webm', 'pdf'];

    private string  $dataset;
    private string  $dataFile;
    private string  $mediaDir;
    private array   $passes;
    private bool    $dryRun;
    private string  $userAgent;
    private ?string $oauthToken = null;
    private float   $lastSparqlTime = 0.0;
    private float   $lastSearchTime = 0.0;
    private float   $lastDownloadTime = 0.0;
    private bool    $rateLimited = false;

    public function __construct(string $dataset, bool $dryRun)
    {
        $this->dataset = $dataset;
        $root = dirname(__DIR__);
        $this->dataFile = "$root/datasets/$dataset/$dataset.php";
        $this->mediaDir = "$root/datasets/$dataset/media/";
        $this->dryRun = $dryRun;

        $configFile = __DIR__ . '/config.php';
        $config = file_exists($configFile) ? require $configFile : [];
        $this->userAgent = $config['wikidata_user_agent'] ?? 'omeka-s/SampleData-module build-media/1.0 (https://omeka.org)';
        $this->oauthToken = $config['wikidata_access_token'] ?? null;

        $strategyFile = __DIR__ . "/strategies/$dataset.php";
        $extra = file_exists($strategyFile) ? require $strategyFile : [];
        // Override passes run before the automated searches so they take precedence
        // over any coincidental Commons match.
        $overridePasses = [];
        $otherExtra = [];
        foreach ($extra as $p) {
            if (($p['type'] ?? null) === 'override') {
                $overridePasses[] = $p;
            } else {
                $otherExtra[] = $p;
            }
        }
        $this->passes = array_merge($overridePasses, self::DEFAULT_PASSES, $otherExtra);
    }

    public function run(): void
    {
        if (!file_exists($this->dataFile)) {
            fwrite(STDERR, "Dataset not found: {$this->dataFile}\n");
            exit(1);
        }

        $data = require $this->dataFile;
        $items = $data['items'] ?? [];
        $pending = array_values(array_filter($items, fn ($i) =>
            empty($i['media']) || !file_exists($this->mediaDir . $i['media'])
        ));

        $total = count($items);
        $done = $total - count($pending);
        echo "Dataset: {$this->dataset} | Items: $total | Already done: $done | Pending: " . count($pending) . "\n";

        if (!$pending) {
            echo "Nothing to do.\n";
            return;
        }

        $urlMap = $this->fetchImages($pending);
        echo "URLs found: " . count($urlMap) . " / " . count($pending) . "\n\n";

        if ($this->dryRun) {
            foreach ($urlMap as $id => $url) {
                echo "  $id -> $url\n";
            }
            echo "\n(dry run — no files written)\n";
            return;
        }

        if (!is_dir($this->mediaDir)) {
            mkdir($this->mediaDir, 0755, true);
        }

        $downloaded = $failed = 0;
        $mediaMap = [];

        foreach ($urlMap as $id => $url) {
            $filename = $this->downloadImage($url, $id);
            if ($filename) {
                $mediaMap[$id] = $filename;
                $downloaded++;
            } else {
                $failed++;
            }
        }

        if ($mediaMap) {
            $this->injectMediaKeys($mediaMap);
            echo "\nUpdated: {$this->dataFile}\n";
        }

        echo "Downloaded: $downloaded | Failed: $failed\n";
    }

    private function fetchImages(array $items): array
    {
        $urlMap = [];
        $pending = $items;

        foreach ($this->passes as $idx => $pass) {
            if (!$pending || $this->rateLimited) {
                break;
            }

            if (isset($pass['search'])) {
                echo "Pass " . ($idx + 1) . ": entity search by {$pass['search']} (" . count($pending) . " pending)\n";
                $found = $this->fetchImagesBySearch($pending, $pass['search'], $pass['property']);
                $this->mergePending($found, $urlMap, $pending);
                continue;
            }

            if (isset($pass['commons_search'])) {
                $fields = $pass['commons_search'];
                echo "Pass " . ($idx + 1) . ": Commons search by " . implode('+', $fields) . " (" . count($pending) . " pending)\n";
                $found = $this->fetchImagesByCommonsSearch($pending, $fields);
                $this->mergePending($found, $urlMap, $pending);
                continue;
            }

            if (isset($pass['skip'])) {
                $skip = array_flip($pass['skip']);
                $before = count($pending);
                $pending = array_values(array_filter($pending, fn ($i) => !isset($skip[$i['id']])));
                $skipped = $before - count($pending);
                if ($skipped) {
                    echo "Pass " . ($idx + 1) . ": skipping $skipped item(s) (no media available)\n";
                }
                continue;
            }

            if (isset($pass['commons_file_overrides'])) {
                $overrides = array_intersect_key($pass['commons_file_overrides'],
                    array_flip(array_column($pending, 'id')));
                if (!$overrides) {
                    continue;
                }
                echo "Pass " . ($idx + 1) . ": Commons file overrides (" . count($overrides) . " entries)\n";
                $found = $this->fetchImagesByCommonsFiles($overrides);
                $this->mergePending($found, $urlMap, $pending);
                continue;
            }

            if (isset($pass['qid_overrides'])) {
                $overrides = array_intersect_key($pass['qid_overrides'],
                    array_flip(array_column($pending, 'id')));
                if (!$overrides) {
                    continue;
                }
                echo "Pass " . ($idx + 1) . ": QID overrides (" . count($overrides) . " entries)\n";
                $found = $this->fetchImagesByQidMap($overrides, $pass['property']);
                $this->mergePending($found, $urlMap, $pending);
                continue;
            }

            $property = $pass['property'];
            $fields = $pass['fields'];
            $normalize = $pass['normalize'] ?? [];
            $primaryField = $fields[0];

            echo "Pass " . ($idx + 1) . ": $property by " . implode('+', $fields) . " (" . count($pending) . " pending)\n";

            $labelIndex = [];
            foreach ($pending as $item) {
                $label = $this->applyNormalize($item[$primaryField] ?? '', $primaryField, $normalize);
                if ($label !== '') {
                    $labelIndex[$label][] = $item['id'];
                }
            }

            $values = $this->buildValues($pending, $fields, $normalize);
            if (!$values) {
                echo "  (no queryable values)\n";
                continue;
            }

            $rows = $this->sparql($this->buildQuery($property, $fields, $values, $pass['secondary_via'] ?? null));
            echo "  SPARQL returned " . count($rows) . " rows\n";

            $found = [];
            foreach ($rows as $row) {
                $imageUrl = $row['image']['value'] ?? null;
                if (!$imageUrl || $this->isBadExtension($imageUrl)) {
                    continue;
                }

                $primaryLabel = $row['primaryLabel']['value'] ?? null;
                if (!$primaryLabel) {
                    continue;
                }

                if (isset($fields[1])) {
                    $secondaryNorm = $this->applyNormalize(
                        $row['secondaryLabel']['value'] ?? '', $fields[1], $normalize
                    );
                    if ($secondaryNorm === '') {
                        continue;
                    }
                }

                foreach ($labelIndex[$primaryLabel] ?? [] as $id) {
                    $found[$id] ??= $imageUrl;
                }
            }

            echo "  Matched: " . count($found) . "\n";
            $this->mergePending($found, $urlMap, $pending);
        }

        if ($pending) {
            echo "\nNo image found for:\n";
            foreach ($pending as $item) {
                $label = $item['dcterms:title'] ?? '';
                echo "  {$item['id']}" . ($label !== '' ? " ($label)" : '') . "\n";
            }
            echo "\n";
        }

        return $urlMap;
    }

    private function fetchImagesBySearch(array $items, string $field, string $property): array
    {
        $qidMap = [];
        foreach ($items as $item) {
            $id = $item['id'] ?? null;
            $query = $item[$field] ?? '';
            if (!$id || $query === '') {
                continue;
            }

            $qid = $this->entitySearch($query);
            if ($qid) {
                $qidMap[$id] = $qid;
                echo "  Found QID $qid for: $query\n";
            } else {
                echo "  No QID for: $query\n";
            }
        }

        if (!$qidMap) {
            return [];
        }

        return $this->fetchImagesByQidMap($qidMap, $property);
    }

    private function fetchImagesByCommonsSearch(array $items, array $fields): array
    {
        $urlMap = [];

        foreach ($items as $item) {
            $id = $item['id'] ?? null;
            if (!$id) {
                continue;
            }

            $query = implode(' ', array_filter(array_map(fn ($f) => $item[$f] ?? '', $fields)));
            if (!$query) {
                continue;
            }

            $filename = $this->commonsSearch($query);
            if (!$filename) {
                echo "  Not found: $query\n";
                continue;
            }

            $url = $this->commonsFileUrl($filename);
            if ($url && !$this->isBadExtension($url)) {
                $urlMap[$id] = $url;
                echo "  Found: $filename\n";
            } else {
                echo "  No usable URL for: $filename\n";
            }
        }

        echo "  Matched: " . count($urlMap) . "\n";
        return $urlMap;
    }

    private function commonsGet(string $url): ?array
    {
        $this->throttle($this->lastSearchTime, self::SEARCH_DELAY);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_USERAGENT => $this->userAgent,
                CURLOPT_TIMEOUT => 10,
            ]);
            $response = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);

            if ($response === false) {
                continue;
            }
            $body = substr($response, $headerSize);
            if ($status === 200 && $body) {
                return json_decode($body, true);
            }
            if ($status === 429) {
                $headers = substr($response, 0, $headerSize);
                preg_match('/Retry-After:\s*(\d+)/i', $headers, $m);
                $wait = isset($m[1]) ? (int) $m[1] : 60;
                $wait = min($wait, 120);
                echo "  [rate limit] sleeping {$wait}s\n";
                sleep($wait);
                continue;
            }
            return null;
        }
        return null;
    }

    private function commonsSearch(string $query): ?string
    {
        $url = 'https://commons.wikimedia.org/w/api.php?' . http_build_query([
            'action' => 'query',
            'list' => 'search',
            'srnamespace' => 6,
            'srsearch' => $query,
            'srlimit' => 1,
            'format' => 'json',
        ]);

        $result = $this->commonsGet($url);
        if (!$result) {
            return null;
        }
        $title = $result['query']['search'][0]['title'] ?? null;
        // Strip "File:" prefix
        return $title ? substr($title, 5) : null;
    }

    private function commonsFileUrl(string $filename): ?string
    {
        $url = 'https://commons.wikimedia.org/w/api.php?' . http_build_query([
            'action' => 'query',
            'titles' => 'File:' . $filename,
            'prop' => 'imageinfo',
            'iiprop' => 'url',
            'iiurlwidth' => self::IMAGE_WIDTH,
            'format' => 'json',
        ]);

        $result = $this->commonsGet($url);
        if (!$result) {
            return null;
        }
        $pages = $result['query']['pages'] ?? [];
        $page = reset($pages);
        return $page['imageinfo'][0]['thumburl'] ?? null;
    }

    private function fetchImagesByCommonsFiles(array $overrides): array
    {
        $urlMap = [];
        foreach ($overrides as $id => $filename) {
            $url = $this->commonsFileUrl($filename);
            if ($url && !$this->isBadExtension($url)) {
                $urlMap[$id] = $url;
                echo "  Found: $filename\n";
            } else {
                fwrite(STDERR, "  No usable URL for: $filename\n");
            }
        }
        echo "  Matched: " . count($urlMap) . "\n";
        return $urlMap;
    }

    private function fetchImagesByQidMap(array $qidMap, string $property): array
    {
        $qidValues = implode(' ', array_map(fn ($q) => "(wd:$q)", array_values($qidMap)));
        $query = "SELECT ?entity ?image WHERE {\n  VALUES (?entity) { $qidValues }\n  ?entity wdt:$property ?image .\n}";

        $rows = $this->sparql($query);
        echo "  SPARQL returned " . count($rows) . " rows\n";

        $qidToIds = [];
        foreach ($qidMap as $id => $qid) {
            $qidToIds[$qid][] = $id;
        }
        $urlMap = [];
        foreach ($rows as $row) {
            $qid = basename($row['entity']['value'] ?? '');
            $imageUrl = $row['image']['value'] ?? null;
            if (!$imageUrl || $this->isBadExtension($imageUrl)) {
                continue;
            }
            foreach ($qidToIds[$qid] ?? [] as $id) {
                $urlMap[$id] ??= $imageUrl;
            }
        }

        echo "  Matched: " . count($urlMap) . "\n";
        return $urlMap;
    }

    private function entitySearch(string $query): ?string
    {
        $this->throttle($this->lastSearchTime, self::SEARCH_DELAY);

        $url = 'https://www.wikidata.org/w/api.php?' . http_build_query([
            'action' => 'wbsearchentities',
            'search' => $query,
            'language' => 'en',
            'type' => 'item',
            'limit' => 1,
            'format' => 'json',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200 || !$body) {
            return null;
        }
        $result = json_decode($body, true);
        return $result['search'][0]['id'] ?? null;
    }

    private function buildValues(array $items, array $fields, array $normalize): string
    {
        $primaryField = $fields[0];
        $secondaryField = $fields[1] ?? null;
        $lines = [];

        foreach ($items as $item) {
            $primary = $this->applyNormalize($item[$primaryField] ?? '', $primaryField, $normalize);
            if ($primary === '') {
                continue;
            }

            if ($secondaryField) {
                $secondary = $this->applyNormalize($item[$secondaryField] ?? '', $secondaryField, $normalize);
                if ($secondary === '') {
                    continue;
                }
                $lines[] = '("' . $this->sparqlStr($primary) . '"@en "' . $this->sparqlStr($secondary) . '")';
            } else {
                $lines[] = '("' . $this->sparqlStr($primary) . '"@en)';
            }
        }

        return implode(' ', $lines);
    }

    private function buildQuery(string $property, array $fields, string $values, ?string $secondaryVia = null): string
    {
        $hasSecondary = isset($fields[1]);
        $select = $hasSecondary ? '?primaryLabel ?secondaryLabel ?image' : '?primaryLabel ?image';
        $valuesClause = $hasSecondary ? '(?primaryLabel ?secondaryLabel)' : '(?primaryLabel)';

        $creatorJoin = ($hasSecondary && $secondaryVia)
            ? "  ?item wdt:$secondaryVia ?creatorItem .\n  ?creatorItem rdfs:label ?creatorLabel .\n  FILTER(langMatches(lang(?creatorLabel), \"en\"))\n  FILTER(CONTAINS(LCASE(STR(?creatorLabel)), LCASE(STR(?secondaryLabel))))\n"
            : '';

        return "SELECT $select WHERE {\n  VALUES $valuesClause { $values }\n  ?item rdfs:label ?primaryLabel .\n  ?item wdt:$property ?image .\n{$creatorJoin}}";
    }

    private function downloadImage(string $url, string $id): ?string
    {
        $ext = $this->extractExt($url);
        if (!$ext) {
            return null;
        }

        $dest = $this->mediaDir . $id . '.' . $ext;
        if (file_exists($dest)) {
            echo "  Skip (exists): $id.$ext\n";
            return $id . '.' . $ext;
        }

        $fetchUrl = $url . '?width=' . self::IMAGE_WIDTH;
        $status = 0;
        $body = null;
        $effectiveUrl = '';

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->throttle($this->lastDownloadTime, self::DOWNLOAD_DELAY);

            $retryAfter = null;
            $ch = curl_init($fetchUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => $this->userAgent,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HEADERFUNCTION => $this->retryAfterClosure($retryAfter),
            ]);
            $body = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);

            if ($status !== 429) {
                break;
            }
            $wait = $retryAfter ?: 60;
            echo "  Rate limited — waiting {$wait}s...\n";
            sleep($wait);
        }

        if ($status !== 200 || !$body) {
            fwrite(STDERR, "  Download failed ($status): $id\n");
            return null;
        }

        $finalExt = $this->extractExt($effectiveUrl) ?: $ext;
        if (in_array($finalExt, self::BAD_EXTENSIONS, true)) {
            fwrite(STDERR, "  Skipped bad extension ($finalExt): $id\n");
            return null;
        }

        $filename = $id . '.' . $finalExt;
        if (file_put_contents($this->mediaDir . $filename, $body) === false) {
            fwrite(STDERR, "  Write failed: {$this->mediaDir}$filename\n");
            return null;
        }

        echo "  Downloaded: $filename (" . number_format(strlen($body)) . " bytes)\n";
        return $filename;
    }

    private function injectMediaKeys(array $mediaMap): void
    {
        $content = file_get_contents($this->dataFile);
        foreach ($mediaMap as $id => $filename) {
            $pattern = "/('id'\s*=>\s*'" . preg_quote($id, '/') . "',)\n/";
            $replace = "$1\n            'media' => '$filename',\n";
            $new = preg_replace($pattern, $replace, $content, 1, $count);
            if ($count === 1) {
                $content = $new;
            } else {
                fwrite(STDERR, "  Could not inject media key for: $id\n");
            }
        }
        file_put_contents($this->dataFile, $content);
    }

    private function mergePending(array $found, array &$urlMap, array &$pending): void
    {
        $urlMap += $found;
        $pending = array_values(array_filter($pending, fn ($i) => !isset($urlMap[$i['id']])));
    }

    private function sparql(string $query): array
    {
        $this->throttle($this->lastSparqlTime, self::SPARQL_DELAY);

        $retryAfter = null;
        $headers = ['Accept: application/sparql-results+json'];
        if ($this->oauthToken) {
            $headers[] = 'Authorization: Bearer ' . $this->oauthToken;
        }
        $ch = curl_init('https://query.wikidata.org/sparql');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['query' => $query]),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADERFUNCTION => $this->retryAfterClosure($retryAfter),
        ]);
        $body = curl_exec($ch);
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

    private function applyNormalize(string $value, string $field, array $normalize): string
    {
        if (isset($normalize[$field])) {
            foreach ((array) $normalize[$field] as $fn) {
                $value = $this->{$fn}($value);
            }
        }
        return trim($value);
    }

    private function normalizeCreator(string $creator): string
    {
        if (str_starts_with(strtolower($creator), 'unknown')) {
            return '';
        }
        $creator = preg_replace('/^After\s+/i', '', $creator);
        $creator = preg_replace('/\s*\(attributed\)/i', '', $creator);
        return trim(preg_split('/\s+and\s+/i', $creator)[0]);
    }

    private function stripLeadingArticle(string $title): string
    {
        if (str_starts_with($title, "L'")) {
            return substr($title, 2);
        }
        foreach (['The', 'A', 'An', 'La', 'Le', 'Les', 'Der', 'Die', 'Das', 'El', 'Il', 'Un', 'Una'] as $article) {
            if (str_starts_with($title, $article . ' ')) {
                return substr($title, strlen($article) + 1);
            }
        }
        return $title;
    }

    private function sparqlStr(string $s): string
    {
        return addcslashes($s, '"\\');
    }

    private function extractExt(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    private function isBadExtension(string $url): bool
    {
        return in_array($this->extractExt($url), self::BAD_EXTENSIONS, true);
    }
}
