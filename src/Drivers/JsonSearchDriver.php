<?php

namespace Tzart\SearchEngine\Drivers;

use Tzart\SearchEngine\Contracts\SearchDriver;
use Tzart\SearchEngine\TreeBuilder;

class JsonSearchDriver implements SearchDriver
{
    protected array $config;      // full package config
    protected array $searchCfg;  // search specific cfg
    protected array $columns;
    protected string $termModel;

    protected static $distanceCache = [];

    public function __construct(array $fullConfig)
    {
        // fullConfig expected to be config('search') array
        $this->config = $fullConfig;
        $this->searchCfg = $fullConfig['search'] ?? [];
        $this->columns = $fullConfig['columns'] ?? [];
        $this->termModel = $fullConfig['models']['term'];
    }

    public function buildIndex(?int $domainId = null): void
    {
        $builder = new TreeBuilder($this->config);
        $builder->build($domainId);
    }

    public function autocomplete(string $query, ?int $domainId = null, array $options = []): array
    {
        // prefer prefix matches, limit small
        $options['limit'] = $options['limit'] ?? 10;
        $options['is_autocomplete'] = true;
        return $this->performSearch($query, $domainId, $options);
    }

    public function search(string $query, ?int $domainId = null, array $options = []): array
    {
        $options['limit'] = $options['limit'] ?? 20;
        $options['is_autocomplete'] = false;
        return $this->performSearch($query, $domainId, $options);
    }

    protected function performSearch(string $query, ?int $domainId, array $options): array
{
    $builder = new TreeBuilder($this->config); // unchanged
    $qLower  = mb_strtolower(trim($query));
    $tokens  = $this->tokenize($qLower);

    if (empty($tokens)) {
        return [];
    }

    // --- Base key generation (same as before)
    $baseKeys = [];
    foreach ($tokens as $tok) {
        $baseKeys[] = ctype_digit($tok) ? 'N:' . $tok : 'T:' . metaphone($tok);
    }
    if (count($tokens) > 1) {
        $baseKeys[] = 'P:' . metaphone($qLower);
    }

    $candidates = [];
    $seen = [];

    // --- Lazy load shards for base keys
    foreach ($baseKeys as $key) {
        $prefix = substr($key, 0, 2);
        $shard  = $builder->loadShard($domainId, $prefix);
        foreach ($shard as $node) {
            if (!isset($seen[$node['id']])) {
                $seen[$node['id']] = true;
                $candidates[] = $node;
            }
        }
    }

    // --- Fallback: load all if too few
    $minCandidates = $options['min_candidates'] ?? ($this->config['search']['min_candidates'] ?? 25);
    if (count($candidates) < $minCandidates) {
        foreach ($builder->load($domainId) as $shard) {
            foreach ($shard as $node) {
                if (!isset($seen[$node['id']])) {
                    $seen[$node['id']] = true;
                    $candidates[] = $node;
                }
            }
        }
    }

    // --- Prefilter by tokens to reduce comparisons
    $candidates = array_filter($candidates, function ($node) use ($tokens) {
        $title = mb_strtolower($node['t']);
        foreach ($tokens as $t) {
            if (mb_strpos($title, $t) !== false) return true;
        }
        return false;
    });

    // --- Prepare scoring variables
    $matches = [];
    static $tokenCache = [];

    foreach ($candidates as $node) {
        $title      = mb_strtolower($node['t']);
        $titleWords = preg_split('/\s+/', $title, -1, PREG_SPLIT_NO_EMPTY);
        $tokenScores = [];
        $foundTokens = 0;

        foreach ($tokens as $tok) {
            // Quick reject if token not in title at all
            if (mb_strpos($title, $tok) === false) {
                continue;
            }

            // Cache to avoid repeated calculations
            $key = $tok . '|' . $title;
            if (!isset($tokenCache[$key])) {
                // Replace expensive Damerau with levenshtein (faster)
                $distance = levenshtein($tok, $title);
                similar_text($tok, $title, $similarityPct);
                $tokenCache[$key] = [$distance, $similarityPct];
            }
            [$distance, $similarityPct] = $tokenCache[$key];

            // Base token score
            $score = $similarityPct - ($distance * 4);

            // --- Token-level boosts
            if (mb_strpos($title, $tok) === 0) {
                $score += $this->config['search']['prefix_boost'] ?? 30;
            } elseif (mb_strpos($title, $tok) !== false) {
                $score += $this->config['search']['substring_boost'] ?? 20;
            }
            if (in_array($tok, $titleWords, true)) {
                $score += $this->config['search']['word_boost'] ?? 50;
                $foundTokens++;
            }

            $tokenScores[] = $score;
        }

        if (empty($tokenScores)) {
            continue;
        }

        // --- Weighted average
        $totalScore = 0;
        foreach ($tokenScores as $i => $s) {
            $weight = 1 + (1 / (1 + strlen($tokens[$i])));
            $totalScore += $s * $weight;
        }
        $totalScore /= count($tokenScores);

        // --- Phrase boost (if all tokens in order)
        if (mb_strpos($title, implode(' ', $tokens)) !== false) {
            $totalScore += 30;
        }

        // --- All tokens present boost / missing penalty
        if ($this->tokensAllPresent($tokens, $title)) {
            $totalScore += 40;
        } elseif ($foundTokens < count($tokens)) {
            $totalScore -= 50;
        }

        $matches[] = [
            'id'    => $node['id'],
            'score' => $totalScore,
            'node'  => $node,
        ];
    }

    // --- Sorting (reuse your function)
    $mode = $options['mode'] ?? 'similarity';
    $matches = $this->sortMatches($matches, $qLower, $mode);

    // --- Limit
    $limit = $options['limit'] ?? 20;
    $matches = array_slice($matches, 0, $limit);

    // --- Return format
    return ($options['return'] ?? 'ids') === 'nodes'
        ? array_map(fn($m) => $m['node'], $matches)
        : array_map(fn($m) => $m['id'], $matches);
    }

     /**
     * Modular sorting strategy for matches.
     */
    protected function sortMatches(array $matches, string $qLower, string $mode): array
    {
        if ($mode === 'exact') {
            usort($matches, function ($a, $b) use ($qLower) {
                $aTitle = mb_strtolower($a['node']['t']);
                $bTitle = mb_strtolower($b['node']['t']);

                $aExact = ($aTitle === $qLower);
                $bExact = ($bTitle === $qLower);
                if ($aExact !== $bExact) {
                    return $bExact <=> $aExact;
                }

                $aPrefix = (mb_strpos($aTitle, $qLower) === 0);
                $bPrefix = (mb_strpos($bTitle, $qLower) === 0);
                if ($aPrefix !== $bPrefix) {
                    return $bPrefix <=> $aPrefix;
                }

                $aSub = (mb_strpos($aTitle, $qLower) !== false);
                $bSub = (mb_strpos($bTitle, $qLower) !== false);
                if ($aSub !== $bSub) {
                    return $bSub <=> $aSub;
                }

                return $b['score'] <=> $a['score'];
            });
        } else {
            // Similarity mode — faster array_multisort
            array_multisort(
                array_column($matches, 'score'),
                SORT_DESC,
                SORT_NUMERIC,
                $matches
            );
        }

        return $matches;
    }

    /**
     * Tokenization helper (unchanged but efficient).
     */
    protected function tokenize(string $text): array
    {
        static $cache = [];
        if (isset($cache[$text])) {
            return $cache[$text];
        }
        $raw = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        return $cache[$text] = array_values(array_filter(array_map('mb_strtolower', $raw)));
    }

    /**
     * Checks if all tokens appear in the title (same).
     */
    protected function tokensAllPresent(array $tokens, string $title): bool
    {
        foreach ($tokens as $t) {
            if (mb_strpos($title, $t) === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * Damerau-Levenshtein distance (kept but optimized with memoization).
     */
    protected function damerauDistance(string $a, string $b, bool $fastMode = true): int
    {
        static $cache = [];

        $key = $a . '|' . $b . '|' . (int)$fastMode;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        if ($a === $b) {
            return $cache[$key] = 0;
        }

        if ($fastMode) {
            $dist = levenshtein($a, $b);
            if (abs(strlen($a) - strlen($b)) <= 1 && $dist > 0) {
                $len = min(strlen($a), strlen($b));
                for ($i = 0; $i < $len - 1; $i++) {
                    if ($a[$i] === $b[$i + 1] && $a[$i + 1] === $b[$i]) {
                        $dist = max(0, $dist - 1);
                        break;
                    }
                }
            }
            return $cache[$key] = $dist;
        }

        // Fall back to full Damerau implementation if needed
        // (your original matrix version can go here)
        return $cache[$key] = $this->damerauDistanceFull($a, $b);
    }

}