<?php

namespace Tzart\SearchEngine\Drivers;

use Tzart\SearchEngine\Contracts\SearchDriver;
use Tzart\SearchEngine\TreeBuilder;
use Tzart\SearchEngine\Helpers\TokenCache;


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
      $builder = new TreeBuilder($this->config);

      // Normalize query
      $qLower = mb_strtolower(trim($query));
      $tokens = $this->tokenize($qLower);
      if (empty($tokens)) return [];

      // Precompute token metaphones, lengths, numeric flags
      $tokenMetaphones = [];
      $tokenLens = [];
      $tokenIsNumeric = [];
      foreach ($tokens as $t) {
          $tokenIsNumeric[$t] = ctype_digit($t);
          $tokenMetaphones[$t] = $tokenIsNumeric[$t] ? null : metaphone($t);
          $tokenLens[$t] = strlen($t);
      }

      // Determine shard prefixes
      $prefixes = [];
      foreach ($tokens as $tok) {
          if ($tokenIsNumeric[$tok]) {
              $prefixes[] = 'N_' . substr($tok, 0, 2);
          } else {
              $prefixes[] = 'T_' . substr($tokenMetaphones[$tok], 0, 3);
          }
      }
      if (count($tokens) > 1) {
          $phrasePhonetic = metaphone($qLower);
          $prefixes[] = 'P_' . substr($phrasePhonetic, 0, 5);
      }

      // --- Load candidates from shards ---
      $candidates = [];
      $seenIds = [];
      $maxNodesPerShard = $options['max_nodes_per_shard'] ?? null;

      foreach ($prefixes as $prefix) {
          foreach ($builder->loadShard($domainId, $prefix, $maxNodesPerShard) as $node) {
              $id = $node['id'];
              if (isset($seenIds[$id])) continue;
              $seenIds[$id] = true;
              $titleLower = mb_strtolower($node['t']);
              $titleWords = preg_split('/\s+/u', $titleLower, -1, PREG_SPLIT_NO_EMPTY);
              $candidates[$id] = [
                  'id' => $id,
                  't' => $node['t'],
                  't_lower' => $titleLower,
                  'words' => $titleWords,
              ];
          }
      }

      // Fallback: load all shards if too few candidates
      $minCandidates = $options['min_candidates'] ?? ($this->config['search']['min_candidates'] ?? 25);
      if (count($candidates) < $minCandidates) {
          foreach ($builder->load($domainId) as $node) {
              $id = $node['id'];
              if (isset($seenIds[$id])) continue;
              $seenIds[$id] = true;
              $titleLower = mb_strtolower($node['t']);
              $titleWords = preg_split('/\s+/u', $titleLower, -1, PREG_SPLIT_NO_EMPTY);
              $candidates[$id] = [
                  'id' => $id,
                  't' => $node['t'],
                  't_lower' => $titleLower,
                  'words' => $titleWords,
              ];
          }
      }

      // --- Prefilter: phonetic-aware for text, exact match for numeric ---
      $prefiltered = [];
      foreach ($candidates as $c) {
          foreach ($tokens as $tok) {
              if ($tokenIsNumeric[$tok]) {
                  if (in_array($tok, $c['words'], true)) {
                      $prefiltered[$c['id']] = $c;
                      break;
                  }
              } else {
                  $tokMeta = $tokenMetaphones[$tok];
                  foreach ($c['words'] as $w) {
                      if (metaphone($w) === $tokMeta) {
                          $prefiltered[$c['id']] = $c;
                          break 2;
                      }
                  }
              }
          }
      }
      $candidates = array_values($prefiltered);
      unset($prefiltered);

      // --- Scoring ---
      $useApcu = function_exists('apcu_fetch') && ini_get('apc.enabled');
      static $tokenCacheStatic = [];
      $tokenCache = &$tokenCacheStatic;
      $matches = [];

      $cfg = $this->config['search'] ?? [];
      $prefixBoost = $cfg['prefix_boost'] ?? 30;
      $substringBoost = $cfg['substring_boost'] ?? 20;
      $wordBoost = $cfg['word_boost'] ?? 50;
      $maxLenDiffForDistance = $cfg['max_len_diff_distance'] ?? 4;
      $distanceCutoff = $cfg['distance_cutoff'] ?? 4;




      $getTokenCache = function(string $tok, string $titleLower, array $titleWords) use (&$tokenCache, $useApcu) {
          $key = $tok . '|' . $titleLower;
          if ($useApcu && ($cached = apcu_fetch($key)) !== false) return $cached;
          if (isset($tokenCache[$key])) return $tokenCache[$key];

          $bestDist = PHP_INT_MAX;
          foreach ($titleWords as $w) {
              if (abs(strlen($tok) - strlen($w)) > 6) continue;
              $d = levenshtein($tok, $w);
              if ($d < $bestDist) $bestDist = $d;
              if ($bestDist === 0) break;
          }
          if ($bestDist === PHP_INT_MAX) $bestDist = levenshtein($tok, $titleLower);

          $maxLen = max(1, max(strlen($tok), strlen($titleLower)));
          $similarityPct = (1 - ($bestDist / $maxLen)) * 100;
          $result = [$bestDist, $similarityPct];

          if ($useApcu) apcu_store($key, $result, 300);
          else $tokenCache[$key] = $result;

          return $result;
      };

      foreach ($candidates as $c) {
          $titleLower = $c['t_lower'];
          $titleWords = $c['words'];
          $tokenScores = [];
          $foundTokens = 0;
          $joinedTokens = implode(' ', $tokens);

          // Precompute lookup map for O(1) membership test
          $titleWordMap = array_flip($titleWords);

          foreach ($tokens as $tok) {
              $pos = mb_strpos($titleLower, $tok);
              if ($pos === false) continue;

              $isNumeric = $tokenIsNumeric[$tok] ?? false;
              $inTitleWords = isset($titleWordMap[$tok]);

              // --- numeric exact match ---
              if ($isNumeric && $inTitleWords) {
                  $tokenScores[] = 120 + $prefixBoost; // heavy boost
                  $foundTokens++;
                  continue;
              }

              // --- text exact match ---
              if ($inTitleWords) {
                  $score = 100 + $wordBoost + ($pos === 0 ? $prefixBoost : $substringBoost);
                  $tokenScores[] = $score;
                  $foundTokens++;
                  continue;
              }

              // --- prefix match ---
              if ($pos === 0) {
                  $tokenScores[] = 80 + $prefixBoost;
                  continue;
              }

              // --- short-circuit long-distance tokens ---
              if (abs(strlen($tok) - strlen($titleLower)) > $maxLenDiffForDistance) {
                  $tokenScores[] = $substringBoost;
                  continue;
              }

              // --- fuzzy match using cache ---
              $tokenCache = new TokenCache(300); // 5 min TTL

              /*
                This code uses the TokenCache class to retrieve or compute the similarity between a token ($tok) 
                and a title ($titleLower) efficiently. The get() method either returns a cached result or executes 
                the provided callback to calculate it. Inside the callback, the Levenshtein distance is computed 
                between the token and each word in the title, skipping words with a length difference greater than 6. 
                The smallest distance found becomes $bestDist. If no close word is found, it compares the token 
                to the full title. $similarityPct is then calculated as a percentage similarity (1 - distance/maxLength). 
                The result [$distance, $similarityPct] is cached for future reuse, optimizing repeated computations.
              */
              [$distance, $similarityPct] = $tokenCache->get($tok, $titleLower, $titleWords, function($tok, $titleLower, $titleWords) {
                  $bestDist = PHP_INT_MAX;
                  foreach ($titleWords as $w) {
                      if (abs(strlen($tok) - strlen($w)) > 6) continue;
                      $d = levenshtein($tok, $w);
                      if ($d < $bestDist) $bestDist = $d;
                      if ($bestDist === 0) break;
                  }
                  if ($bestDist === PHP_INT_MAX) $bestDist = levenshtein($tok, $titleLower);

                  $maxLen = max(1, max(strlen($tok), strlen($titleLower)));
                  $similarityPct = (1 - ($bestDist / $maxLen)) * 100;

                  return [$bestDist, $similarityPct];
              });

              if ($distance > $distanceCutoff && $similarityPct < 30) continue;

              $score = $similarityPct - ($distance * 6);
              $score += ($pos === 0 ? $prefixBoost : $substringBoost);
              $tokenScores[] = $score;
          }


          if (empty($tokenScores)) continue;

          // Initialize total score accumulator
          $totalScore = 0;
          $count = count($tokenScores);

          // Precompute token weights to avoid misalignment
          $tokenWeights = [];
          foreach ($tokens as $tok) {
              // Weight shorter tokens slightly higher (avoiding division by zero)
              // This gives some preference to shorter words without letting them dominate
              $tokenWeights[$tok] = 1 + (1 / (1 + ($tokenLens[$tok] ?? 1)));
          }

          // Compute weighted average of token scores
          foreach ($tokenScores as $i => $score) {
              // Get corresponding token safely (handles mismatched arrays)
              $tok = $tokens[min($i, count($tokens) - 1)];
              $weight = $tokenWeights[$tok];
              $totalScore += $score * $weight;
          }

          // Average the total score
          $totalScore /= max(1, $count); // prevent division by zero

          // Bonus if all search tokens appear consecutively in the title
          if (mb_strpos($titleLower, $joinedTokens) !== false) {
              $totalScore += 25;
          }

          // Bonus if all individual tokens are present in the title
          if ($this->tokensAllPresent($tokens, $titleLower)) {
              $totalScore += 40;
          } else {
              // Penalize proportionally for missing tokens rather than flat -20
              $missingFraction = 1 - ($foundTokens / max(1, count($tokens)));
              $totalScore -= 20 * $missingFraction;
          }

          // Clamp the score to a reasonable range to avoid outliers
          $totalScore = max(-100, min(1000, $totalScore));

          // Store the final match result
          $matches[] = [
              'id' => $c['id'],
              'score' => $totalScore,
              'node' => ['id' => $c['id'], 't' => $c['t']],
          ];

      }

      // Sorting & limit
      $mode = $options['mode'] ?? 'similarity';
      $matches = $this->sortMatches($matches, $qLower, $mode);
      $limit = $options['limit'] ?? 100;
      $matches = array_slice($matches, 0, $limit);

      return ($options['return'] ?? 'ids') === 'nodes'
          ? array_map(fn($m) => $m['node'], $matches)
          : array_map(fn($m) => $m['id'], $matches);
  }


  /**
   * Improved sortMatches - uses array_multisort for similarity and
   * a tighter comparator for 'exact' mode.
   */
  protected function sortMatches(array $matches, string $qLower, string $mode): array
  {
    if ($mode === 'exact') {
      usort($matches, function ($a, $b) use ($qLower) {
        $aTitle = mb_strtolower($a['node']['t']);
        $bTitle = mb_strtolower($b['node']['t']);

        // exact match first
        $aExact = ($aTitle === $qLower);
        $bExact = ($bTitle === $qLower);
        if ($aExact !== $bExact) return $bExact <=> $aExact;

        // then prefix
        $aPref = (mb_strpos($aTitle, $qLower) === 0);
        $bPref = (mb_strpos($bTitle, $qLower) === 0);
        if ($aPref !== $bPref) return $bPref <=> $aPref;

        // then substring
        $aSub = (mb_strpos($aTitle, $qLower) !== false);
        $bSub = (mb_strpos($bTitle, $qLower) !== false);
        if ($aSub !== $bSub) return $bSub <=> $aSub;

        // fallback score
        return $b['score'] <=> $a['score'];
      });
    } else {
      // similarity: fastest multi-sort by score DESC
      if (!empty($matches)) {
          $scores = array_column($matches, 'score');
          array_multisort($scores, SORT_DESC, SORT_NUMERIC, $matches);
      }
    }
    return $matches;
  }

  /**
   * tokenize() unchanged but cached. Keeps unicode-aware splitting.
   */
  protected function tokenize(string $text): array
  {
    static $cache = [];
    if (isset($cache[$text])) return $cache[$text];
    $raw = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    return $cache[$text] = array_values(array_filter(array_map('mb_strtolower', $raw)));
  }

  /**
   * tokensAllPresent unchanged.
   */
  protected function tokensAllPresent(array $tokens, string $title): bool
  {
    foreach ($tokens as $t) {
        if (mb_strpos($title, $t) === false) return false;
    }
    return true;
  }

  /**
   * Damerau distance - fast hybrid: use levenshtein + simple transposition detection
   * but we keep a full method available for rare heavy use (not invoked by default).
   */
  protected function damerauDistance(string $a, string $b, bool $fastMode = true): int
  {
    static $cache = [];
    $key = $a . '|' . $b . '|' . (int)$fastMode;
    if (isset($cache[$key])) return $cache[$key];

    if ($a === $b) return $cache[$key] = 0;

    if ($fastMode) {
        $dist = levenshtein($a, $b);

        // quick detection for adjacent transposition if lengths permit
        if (abs(strlen($a) - strlen($b)) <= 1 && $dist > 0) {
            $len = min(strlen($a), strlen($b));
            for ($i = 0; $i < $len - 1; $i++) {
                if ($a[$i] === $b[$i + 1] && $a[$i + 1] === $b[$i]) {
                    // adjust one op for that transposition
                    $dist = max(0, $dist - 1);
                    break;
                }
            }
        }
        return $cache[$key] = $dist;
    }

    // If needed, fallback to a full Damerau implementation - place your full function here.
    return $cache[$key] = $this->damerauDistanceFull($a, $b);
  }

  /**
   * Damerau-Levenshtein distance (kept but optimized with memoization).
   */
  protected function damerauDistanceFull(string $a, string $b): int
  {
    $key = $a . '|' . $b;
    static $distanceCache = [];
    if (isset($distanceCache[$key])) {
        return $distanceCache[$key];
    }

    // Use levenshtein() if strings are long (faster)
    if (strlen($a) > 20 || strlen($b) > 20) {
        return $distanceCache[$key] = levenshtein($a, $b);
    }

    $lenA = strlen($a);
    $lenB = strlen($b);
    $d = [];
    for ($i = 0; $i <= $lenA; $i++) $d[$i][0] = $i;
    for ($j = 0; $j <= $lenB; $j++) $d[0][$j] = $j;

    for ($i = 1; $i <= $lenA; $i++) {
        for ($j = 1; $j <= $lenB; $j++) {
            $cost = ($a[$i - 1] === $b[$j - 1]) ? 0 : 1;
            $d[$i][$j] = min(
                $d[$i - 1][$j] + 1,     // deletion
                $d[$i][$j - 1] + 1,     // insertion
                $d[$i - 1][$j - 1] + $cost // substitution
            );
            if (
                $i > 1 && $j > 1 &&
                $a[$i - 1] === $b[$j - 2] &&
                $a[$i - 2] === $b[$j - 1]
            ) {
                $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + $cost);
            }
        }
    }

    return $distanceCache[$key] = $d[$lenA][$lenB];
  }
}