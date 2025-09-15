<?php

namespace Tzar\SearchEngine\Drivers;

use Tzar\SearchEngine\Contracts\SearchDriver;
use Tzar\SearchEngine\TreeBuilder;

class JsonSearchDriver implements SearchDriver
{
    protected array $config;      // full package config
    protected array $searchCfg;  // search specific cfg
    protected array $columns;
    protected string $termModel;

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
        $builder = new TreeBuilder($this->termModel, $this->columns, $this->searchCfg);
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
        $builder = new TreeBuilder($this->termModel, $this->columns, $this->searchCfg);
        $tree = $builder->load($domainId) ?? $builder->build($domainId);

        // options
        $minRatio     = $options['min_ratio'] ?? ($this->searchCfg['similarity_ratio'] ?? 40);
        $maxDistance  = $options['max_distance'] ?? ($this->searchCfg['fuzzy_threshold'] ?? 2);
        $branchDist   = $options['branch_distance'] ?? ($this->searchCfg['branch_distance'] ?? 1);
        $minCandidates= $options['min_candidates'] ?? ($this->searchCfg['min_candidates'] ?? 25);
        $tokenMode    = $options['token_mode'] ?? ($this->searchCfg['token_mode'] ?? 'any');
        $substrMinLen = $options['substring_min_len'] ?? ($this->searchCfg['substring_min_len'] ?? 3);
        $substrBoost  = $options['substring_boost'] ?? ($this->searchCfg['substring_boost'] ?? 20);
        $prefixBoost  = $options['prefix_boost'] ?? ($this->searchCfg['prefix_boost'] ?? 30);
        $returnFormat = $options['return'] ?? ($this->searchCfg['return'] ?? 'ids');

        $categoryFilter = $options['category_ids'] ?? null;

        $qLower = mb_strtolower($query);
        $tokens = $this->tokenize($qLower);

        // build base keys: token keys first, then phrase key if multi-token
        $baseKeys = [];
        foreach ($tokens as $tok) {
            if (ctype_digit($tok)) {
                $baseKeys[] = 'N:' . $tok;
            } else {
                $baseKeys[] = 'T:' . metaphone($tok);
            }
        }
        if (count($tokens) > 1) {
            $baseKeys[] = 'P:' . metaphone($qLower);
        }

        // collect candidates from exact branch keys
        $seen = [];
        $candidates = [];

        foreach ($baseKeys as $k) {
            if (!empty($tree[$k])) {
                foreach ($tree[$k] as $node) {
                    if ($categoryFilter && !array_intersect($categoryFilter, $node['category_ids'])) {
                        continue;
                    }
                    if (!isset($seen[$node['id']])) {
                        $seen[$node['id']] = true;
                        $candidates[] = $node;
                    }
                }
            }
        }

        // Expand to nearby branches if too few candidates
        if (count($candidates) < $minCandidates && $branchDist > 0) {
            $allKeys = array_keys($tree);
            foreach ($baseKeys as $k) {
                foreach ($allKeys as $ak) {
                    if (levenshtein($k, $ak) <= $branchDist) {
                        foreach ($tree[$ak] as $node) {
                            if ($categoryFilter && !array_intersect($categoryFilter, $node['category_ids'])) {
                                continue;
                            }
                            if (!isset($seen[$node['id']])) {
                                $seen[$node['id']] = true;
                                $candidates[] = $node;
                            }
                        }
                    }
                }
            }
        }

        // If still empty (edge case), fall back to scanning entire tree (careful: expensive)
        if (empty($candidates)) {
            foreach ($tree as $branch) {
                foreach ($branch as $node) {
                    if ($categoryFilter && !array_intersect($categoryFilter, $node['category_ids'])) {
                        continue;
                    }
                    if (!isset($seen[$node['id']])) {
                        $seen[$node['id']] = true;
                        $candidates[] = $node;
                    }
                }
            }
        }

        // scoring
        $matches = [];
        foreach ($candidates as $node) {
            $title = mb_strtolower($node['title']);

            // enforce token_mode = 'all' if requested
            if ($tokenMode === 'all' && !$this->tokensAllPresent($tokens, $title)) {
                continue;
            }

            $distance = levenshtein($qLower, $title);
            similar_text($qLower, $title, $similarityPct); // percent in $similarityPct

            $score = $similarityPct - ($distance * 5);

            // substring boost
            if (mb_strlen($qLower) >= $substrMinLen && mb_strpos($title, $qLower) !== false) {
                $score += $substrBoost;
            }

            // prefix boost (autocomplete)
            if (!empty($options['is_autocomplete']) && mb_strpos($title, $qLower) === 0) {
                $score += $prefixBoost;
            }

            if ($distance <= $maxDistance || $similarityPct >= $minRatio || !empty($options['is_autocomplete'])) {
                $matches[] = [
                    'id' => $node['id'],
                    'score' => $score,
                    'node' => $node,
                ];
            }
        }

        // sort and limit
        usort($matches, fn($a, $b) => $b['score'] <=> $a['score']);

        $limit = $options['limit'] ?? 20;
        $matches = array_slice($matches, 0, $limit);

        // return format
        if ($returnFormat === 'nodes') {
            return array_map(fn($m) => $m['node'], $matches);
        }

        // default: ids
        return array_map(fn($m) => $m['id'], $matches);
    }

    protected function tokenize(string $text): array
    {
        $raw = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_filter(array_map(fn($t) => mb_strtolower($t), $raw)));
    }

    protected function tokensAllPresent(array $tokens, string $title): bool
    {
        foreach ($tokens as $t) {
            if (mb_strpos($title, $t) === false) return false;
        }
        return true;
    }
}
