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
      $qLower = mb_strtolower(trim(string: $query));
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
              // $titleWords = $this->tokenize($titleLower);
              $candidates[$id] = [
                  'id' => $id,
                  't' => $node['t'],
                  't_lower' => $titleLower,
                  // 'words' => $titleWords,
                  'phonetic' => $node['h'] ?? null,
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
              // $titleWords = $this->tokenize($titleLower);
              $candidates[$id] = [
                  'id' => $id,
                  't' => $node['t'],
                  't_lower' => $titleLower,
                  // 'words' => $titleWords,
                  'phonetic' => $node['h'] ?? null,
              ];
          }
      }

      // --- Prefilter: phonetic-aware for text, exact match for numeric, substring match for text ---
      $prefiltered = [];

      foreach ($candidates as $c) {
          $matchedTokenCount = 0;

          foreach ($tokens as $tok) {
              if ($tokenIsNumeric[$tok]) {
                  // Lazy tokenize words only if needed
                  if (!isset($c['words'])) {
                      $c['words'] = $this->tokenize($c['t_lower']);
                  }

                  // Numeric exact match
                  if (in_array($tok, $c['words'], true)) {
                      $matchedTokenCount++;
                  }
              } else {
                  $tokMeta = $tokenMetaphones[$tok] ?? null;

                  if (!$tokMeta) continue;

                  $prefixMatched = false;

                  // Full phrase phonetic match
                  if (isset($c['phonetic']) && str_starts_with($c['phonetic'], $tokMeta)) {
                      $prefixMatched = true;
                  } else {
                      // Lazy per-word metaphone
                      if (!isset($c['words'])) {
                          $c['words'] = $this->tokenize($c['t_lower']);
                      }
                      if (!isset($c['wordMetaphones'])) {
                          $c['wordMetaphones'] = array_map(fn($w) => metaphone($w), $c['words']);
                      }

                      // Per-word phonetic prefix match
                      foreach ($c['wordMetaphones'] as $wMeta) {
                          if ($wMeta && str_starts_with($wMeta, $tokMeta)) {
                              $prefixMatched = true;
                              break;
                          }
                      }
                  }

                  // --- Substring match ---
                  if (!$prefixMatched && mb_strpos($c['t_lower'], $tok) !== false) {
                      $prefixMatched = true;
                  }

                  if ($prefixMatched) {
                      $matchedTokenCount++;
                  }
              }
          }

          if ($matchedTokenCount > 0) {
              $c['matchedTokenCount'] = $matchedTokenCount;
              $prefiltered[$c['id']] = $c;
          }
      }



      $candidates = array_values($prefiltered);
      unset($prefiltered);

      // --- Scoring ---
      $matches = [];

      $cfg = $this->config['search'] ?? [];
      $prefixBoost = $cfg['prefix_boost'] ?? 30;
      $substringBoost = $cfg['substring_boost'] ?? 20;
      $wordBoost = $cfg['word_boost'] ?? 50;
      $maxLenDiffForDistance = $cfg['max_len_diff_distance'] ?? 4;
      $distanceCutoff = $cfg['distance_cutoff'] ?? 4;

      $joinedTokens = implode(' ', $tokens);
      
      foreach ($candidates as $c) {
          $titleLower = $c['t_lower'];

          // Lazy tokenize if not already done in prefilter
          $titleWords = $c['words'] ?? $this->tokenize($titleLower);
          $c['words'] = $titleWords;

          // Precompute per-word metaphones (only once, lazily)
          $wordMetaphones = $c['wordMetaphones'] ?? array_map(fn($w) => metaphone($w), $titleWords);
          $c['wordMetaphones'] = $wordMetaphones;

          // Build quick lookup map for exact membership tests
          $titleWordMap = array_flip($titleWords);
          // Precompute title word lengths once
          $titleWordLens = array_map('strlen', $titleWords);

          $tokenScores = [];
          $foundTokens = $c['matchedTokenCount'] ?? 0; // from prefilter
          

          foreach ($tokens as $tok) {
              $isNumeric = $tokenIsNumeric[$tok] ?? false;
              $inTitleWords = isset($titleWordMap[$tok]);

              // --- numeric exact match ---
              if ($isNumeric && $inTitleWords) {
                  $tokenScores[] = 120 + $prefixBoost;
                  continue;
              }

              // --- text exact match ---
              if ($inTitleWords) {
                  $tokenScores[] = 100 + $wordBoost;
                  continue;
              }

              $tokLen = $tokenLens[$tok] ?? strlen($tok);
              // --- fuzzy match using cache (for non-numeric unmatched tokens) ---
              [$distance, $similarityPct] = $tokenCache->get($tok, $titleLower, $titleWords, function($tok, $titleLower, $titleWords) use ($tokLen, $titleWordLens) {
                  $bestDist = PHP_INT_MAX;
                   foreach ($titleWords as $i => $w) {
                      if (abs($tokLen - $titleWordLens[$i]) > 6) continue; // use precomputed lengths
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
              $tokenScores[] = $score;
          }

          if (empty($tokenScores)) continue;

          // --- weighted average over per-token scores ---
          $tokenWeights = [];
          foreach ($tokens as $tok) {
              $tokenWeights[$tok] = 1 + (1 / (1 + ($tokenLens[$tok] ?? 1)));
          }

          $weightedSum = 0;
          $weightTotal = 0;
          foreach ($tokens as $i => $tok) {
              if (!isset($tokenScores[$i])) continue;
              $w = $tokenWeights[$tok];
              $weightedSum += $tokenScores[$i] * $w;
              $weightTotal += $w;
          }

          $avgTokenScore = $weightedSum / max(1, $weightTotal);
          $totalScore = $avgTokenScore;

          // --- global coverage adjustments ---
          $totalTokens = count($tokens);
          $coverageFraction = $foundTokens / max(1, $totalTokens);

          // Prefix coverage boost (from prefilter)
          if (($c['matchedTokenCount'] ?? 0) > 0) {
              $totalScore += ($coverageFraction * $prefixBoost);
          }

          // Bonus if all search tokens appear consecutively
          if (mb_strpos($titleLower, $joinedTokens) !== false) {
              $totalScore += 25;
          }

          // Balanced penalty/reward for token coverage
          $totalScore += $coverageFraction * 20; // reward for coverage
          $totalScore -= (1 - $coverageFraction) * 10; // small penalty for missing ones

          // Clamp the final score
          $totalScore = max(-100, min(1000, $totalScore));

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

      // Lowercase and trim
      $text = mb_strtolower(trim($text));

      // Insert spaces between digits and letters (both directions)
      $text = preg_replace('/(?<=\d)(?=\p{L})/u', ' ', $text);
      $text = preg_replace('/(?<=\p{L})(?=\d)/u', ' ', $text);

      // Remove punctuation except whitespace
      $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);

      // Split on whitespace
      $tokens = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

      // Normalize tokens: filter junk & remove leading zeros from numeric tokens
      $tokens = array_map(function ($t) {
          if (ctype_digit($t)) {
              return ltrim($t, '0') ?: '0'; // keep '0' if all zeros
          }
          return $t;
      }, $tokens);

      $tokens = array_filter($tokens, function ($t) {
          return mb_strlen($t) > 1 || ctype_digit($t);
      });

      return $cache[$text] = array_values($tokens);
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