<?php

namespace Tzart\SearchEngine;

use Illuminate\Support\Facades\Cache;

class SearchManager
{
    protected $config;
    protected static $distanceCache = [];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Perform fuzzy search across terms (and categories via embedded ids).
     */
    public function search(string $query, $domainId = null): array
    {
        $tokens = explode(' ', strtolower($query));
        $results = [];

        foreach ($tokens as $token) {
            $phonetic = metaphone($token);
            $prefix   = substr($phonetic, 0, 2);
            $tree     = $this->loadShard($domainId, $prefix);

            foreach ($tree as $node) {
                $dist = $this->damerauDistance($token, strtolower($node['t']));
                if ($dist <= $this->config['search']['fuzzy_threshold']) {
                    $results[$node['id']] = $node;
                }

                // substring boost (n-grams)
                if (strlen($token) >= $this->config['search']['substring_min_len'] &&
                    stripos($node['t'], $token) !== false) {
                    $results[$node['id']] = $node;
                }
            }
        }

        // Hybrid fallback to DB if not enough results
        if (count($results) < $this->config['search']['min_candidates']) {
            $extra = $this->dbFallback($query, $domainId);
            foreach ($extra as $row) {
                $results[$row['id']] = $row;
            }
        }

        return array_values($results);
    }

    /**
     * Load shard from filesystem or cache.
     */
    protected function loadShard($domainId, string $prefix): array
    {
        $cacheKey = "search_shard:" . ($domainId ?? 'global') . ":$prefix";

        // 1. Try in-memory static cache first (fastest).
        static $shardCache = [];
        if (isset($shardCache[$cacheKey])) {
            return $shardCache[$cacheKey];
        }

        // 2. Try Laravel cache (Redis / file / array).
        if (Cache::has($cacheKey)) {
            return $shardCache[$cacheKey] = Cache::get($cacheKey);
        }

        // 3. Fallback to file.
        $base = $this->config['search']['tree_path'] . '/' . ($domainId ?? 'global');
        $path = "$base/{$prefix}.json";

        if (!file_exists($path)) {
            return $shardCache[$cacheKey] = [];
        }

        $raw = file_get_contents($path);

        $data = $this->config['search']['use_msgpack']
            ? msgpack_unpack($raw)
            : json_decode($raw, true);

        // 4. Store into Laravel cache for future calls.
        Cache::put($cacheKey, $data, $this->config['search']['cache_ttl'] ?? 3600);

        // 5. Also keep in static cache for same-request hits.
        return $shardCache[$cacheKey] = $data;
    }

    /**
     * Fallback DB query (LIKE search).
     */
    protected function dbFallback(string $query, $domainId): array
    {
        $termModel = $this->config['models']['term'];
        $cols      = $this->config['columns'];

        $q = $termModel::query();

        if ($domainId && $cols['domain_id']) {
            $q->where($cols['domain_id'], $domainId);
        }

        $rows = $q->where($cols['term_title'], 'LIKE', "%{$query}%")->get();

        return $rows->map(function ($term) use ($cols) {
            return [
                'id' => $term->{$cols['term_id']},
                't'  => $term->{$cols['term_title']},
                'y'  => $cols['term_type'] ? $term->{$cols['term_type']} : null,
                'c'  => $term->categories->pluck($cols['category_id'])->all(),
                'h'  => metaphone(strtolower($term->{$cols['term_title']})),
            ];
        })->all();
    }

    /**
     * Damerau–Levenshtein distance with caching.
     */
    protected function damerauDistance(string $a, string $b): int
    {
        $key = $a.'|'.$b;
        if (isset(self::$distanceCache[$key])) {
            return self::$distanceCache[$key];
        }

        $lenA = strlen($a);
        $lenB = strlen($b);

        $d = [];
        $maxDist = $lenA + $lenB;

        for ($i = 0; $i <= $lenA; $i++) {
            $d[$i] = [];
            $d[$i][0] = $i;
        }
        for ($j = 0; $j <= $lenB; $j++) {
            $d[0][$j] = $j;
        }

        for ($i = 1; $i <= $lenA; $i++) {
            for ($j = 1; $j <= $lenB; $j++) {
                $cost = ($a[$i - 1] === $b[$j - 1]) ? 0 : 1;

                $d[$i][$j] = min(
                    $d[$i - 1][$j] + 1,      // deletion
                    $d[$i][$j - 1] + 1,      // insertion
                    $d[$i - 1][$j - 1] + $cost // substitution
                );

                // transposition
                if ($i > 1 && $j > 1 &&
                    $a[$i - 1] === $b[$j - 2] &&
                    $a[$i - 2] === $b[$j - 1]) {
                    $d[$i][$j] = min(
                        $d[$i][$j],
                        $d[$i - 2][$j - 2] + $cost
                    );
                }
            }
        }

        return self::$distanceCache[$key] = $d[$lenA][$lenB];
    }
}
