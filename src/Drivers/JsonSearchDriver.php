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
      $builder = new TreeBuilder($this->config); // pass full package config

      $qLower = mb_strtolower($query);
      $tokens = preg_split('/\s+/', $qLower, -1, PREG_SPLIT_NO_EMPTY);

      $baseKeys = [];
      foreach ($tokens as $tok) {
          $baseKeys[] = ctype_digit($tok) ? 'N:' . $tok : 'T:' . metaphone($tok);
      }
      if (count($tokens) > 1) {
          $baseKeys[] = 'P:' . metaphone($qLower);
      }

      $candidates = [];
      $seen = [];

      // Lazy load shards only for base keys
      foreach ($baseKeys as $key) {
          $prefix = substr($key, 0, 2);
          $shard = $builder->loadShard($domainId, $prefix);

          foreach ($shard as $node) {
              if (!isset($seen[$node['id']])) {
                  $seen[$node['id']] = true;
                  $candidates[] = $node;
              }
          }
      }

      // Fallback if too few candidates
      $minCandidates = $options['min_candidates'] ?? ($this->config['search']['min_candidates'] ?? 25);
      if (count($candidates) < $minCandidates) {
          $allShards = $builder->load($domainId);
          foreach ($allShards as $shard) {
              foreach ($shard as $node) {
                  if (!isset($seen[$node['id']])) {
                      $seen[$node['id']] = true;
                      $candidates[] = $node;
                  }
              }
          }
      }

      // Scoring + extra fields for sorting
      $matches = [];
      foreach ($candidates as $node) {
          $title = mb_strtolower($node['t']);
          $titleWords = preg_split('/\s+/', $title, -1, PREG_SPLIT_NO_EMPTY);

          $distance = $this->damerauDistance($qLower, $title);
          similar_text($qLower, $title, $similarityPct);

          $score = $similarityPct - ($distance * 5);

          // Substring boost
          if (mb_strlen($qLower) >= ($this->config['search']['substring_min_len'] ?? 3) &&
              mb_strpos($title, $qLower) !== false
          ) {
              $score += $this->config['search']['substring_boost'] ?? 20;
          }

          // Prefix boost
          if (mb_strpos($title, $qLower) === 0) {
              $score += $this->config['search']['prefix_boost'] ?? 30;
          }

          // Whole word boost
          if (in_array($qLower, $titleWords, true)) {
              $score += $this->config['search']['word_boost'] ?? 50;
          }

          $matches[] = [
              'id'         => $node['id'],
              'score'      => $score,
              'similarity' => $similarityPct,
              'distance'   => $distance,
              'node'       => $node,
          ];
      }

      // Sort according to user mode
      $mode = $options['mode'] ?? 'similarity'; // "similarity" | "exact"
      $matches = $this->sortMatches($matches, $qLower, $mode);

      // Limit
      $limit = $options['limit'] ?? 20;
      $matches = array_slice($matches, 0, $limit);

      // Return format
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

              $aWords = preg_split('/\s+/', $aTitle, -1, PREG_SPLIT_NO_EMPTY);
              $bWords = preg_split('/\s+/', $bTitle, -1, PREG_SPLIT_NO_EMPTY);

              $aWordMatch = in_array($qLower, $aWords, true);
              $bWordMatch = in_array($qLower, $bWords, true);
              if ($aWordMatch !== $bWordMatch) {
                  return $bWordMatch <=> $aWordMatch; // word match first
              }

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

              return $b['similarity'] <=> $a['similarity'];
          });
      } else {
          // Similarity-first mode with word-match preference
          usort($matches, function ($a, $b) use ($qLower) {
              $aTitle = mb_strtolower($a['node']['t']);
              $bTitle = mb_strtolower($b['node']['t']);
              $aWords = preg_split('/\s+/', $aTitle, -1, PREG_SPLIT_NO_EMPTY);
              $bWords = preg_split('/\s+/', $bTitle, -1, PREG_SPLIT_NO_EMPTY);

              $aWordMatch = in_array($qLower, $aWords, true);
              $bWordMatch = in_array($qLower, $bWords, true);
              if ($aWordMatch !== $bWordMatch) {
                  return $bWordMatch <=> $aWordMatch;
              }

              $cmp = $b['similarity'] <=> $a['similarity'];
              if ($cmp !== 0) return $cmp;

              return $a['distance'] <=> $b['distance'];
          });
      }

      return $matches;
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