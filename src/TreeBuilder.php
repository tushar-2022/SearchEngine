<?php

namespace Tzart\SearchEngine;

use Illuminate\Support\Facades\File;
use Tzart\SearchEngine\Helpers\MmapFile;

class TreeBuilder
{
    protected array $config;
    protected string $termModel;
    protected ?string $categoryModel;
    protected array $columns;
    protected string $categoryRelation;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->termModel = $config['models']['term'];
        $this->categoryModel = $config['models']['category'] ?? null;
        $this->columns = $config['columns'];
        $this->categoryRelation = $config['relations']['term_category'] ?? 'categories';
    }

    
    /**
     * Build shards with 2-letter node hashing + reference-only T/N/P shards.
     */
    public function build(?int $domainId = null): array
    {
        $query = $this->termModel::query();
        if ($domainId && $this->columns['domain_id']) {
            $query->where($this->columns['domain_id'], $domainId);
        }

        $base = rtrim($this->config['search']['tree_path'], '/') . '/' . ($domainId ?? 'global');
        File::ensureDirectoryExists("$base/nodes");
        File::ensureDirectoryExists("$base/T");
        File::ensureDirectoryExists("$base/N");
        File::ensureDirectoryExists("$base/P");

        $shards = [];

        $query->with($this->categoryRelation)->chunk(300, function ($terms) use ($base, &$shards) {

            foreach ($terms as $term) {

                $title = strtolower($term->{$this->columns['term_title']});

                /*
                 * It inserts a space between a digit and a letter when they touch:
                 * 23v → 23 v
                 * v23 → v 23
                 * abc123def → abc 123 def
                */
                $clean = preg_replace('/(?<=\d)(?=[\p{L}])|(?<=\p{L})(?=\d)/u', ' ', $title);
                /*
                 * Remove any character that is not:
                 * A letter (\p{L})
                 * A number (\p{N})
                 * Whitespace (\s)
                */
                $clean = preg_replace('/[^\p{L}\p{N}\s]/u', '', $clean);

                // Split into words
                $words = array_values(array_filter(preg_split('/\s+/', trim($clean))));

                
                $node = [
                    'id' => $term->{$this->columns['term_id']},
                    't'  => $term->{$this->columns['term_title']},
                    'y'  => $this->columns['term_type'] ? $term->{$this->columns['term_type']} : null,
                    'c'  => $term->{$this->categoryRelation}->pluck($this->columns['category_id'] ?? 'category_id')->all(),
                ];

                // -------- 1) Store node in hashed node bucket --------
                $nodePrefix = $this->twoLetterHash($node['t']);
                $nodeFile = "$base/nodes/{$nodePrefix}.jsonl";

                $byteOffset = $this->appendJsonLine($nodeFile, $node);

                // Reference for T/N/P shards
                $ref = [$nodePrefix . ".jsonl", $byteOffset];

                // -------- 2) Build T shards (phonetic of words) --------
                foreach ($words as $w) {

                    if (ctype_digit($w)) {
                        // -------- Numeric (N) shard --------

                        // Clean leading zeros
                        $num = ltrim($w, '0') ?: '0';

                        // Shard dir by first 2 digits
                        $nshard = substr($num, 0, 2);
                        $shardDir = "$base/N/$nshard";
                        File::ensureDirectoryExists($shardDir);

                        // Filename: 2–4 digit prefix
                        $prefixLen = min(strlen($num), 4);
                        $filePrefix = substr($num, 0, $prefixLen);
                        $fname = "$shardDir/{$filePrefix}.jsonl";


                        // Append reference
                        $offset = $this->appendJsonLine($fname, $ref);
                        $this->appendIndexOffset($fname, $offset);
                        // Record shard
                        $shards["N/$nshard"] = true;
                    } else {
                        // -------- Text (T) shard --------
                        $h = metaphone($w);
                        if (!$h) continue;

                        $hashPrefix = substr($this->twoLetterHash($h), 0, 2); // map to 2 letters
                        $shardDir = "$base/T/$hashPrefix";
                        File::ensureDirectoryExists($shardDir);

                        $fname = "$shardDir/" . substr($h, 0, 3) . ".jsonl";
                        $offset = $this->appendJsonLine($fname, $ref);
                        $this->appendIndexOffset($fname, $offset);
                        $shards["T/$hashPrefix"] = true;
                    }
                }

                // -------- 3) Phrase-level P shards --------
                $stopWords = ['a','an','the','in','on','of','and','for','to','is','at','with','from','by'];
                $cleanWords = [];
                foreach ($words as $w) {
                    if (!in_array($w, $stopWords)) {
                        $cleanWords[] = $w;
                    }
                }



                $count = count($cleanWords);
                // -------- WEIGHTED ALL-PAIRS PHRASE SHARDS --------
                if ($count > 1) {

                    $seen = [];

                    for ($i = 0; $i < $count; $i++) {
                        for ($j = $i + 1; $j < $count; $j++) {

                            $w1 = $cleanWords[$i];
                            $w2 = $cleanWords[$j];

                            // ----- Canonical order-invariant key -----
                            $canonical = [$w1, $w2];
                            sort($canonical);
                            $canonicalKey = implode(' ', $canonical);

                            if (isset($seen[$canonicalKey])) continue;
                            $seen[$canonicalKey] = true;

                            // ----- Calculate distance -----
                            $distance = $j - $i;

                            // ----- Weight assignment -----
                            if ($distance === 1) {
                                $weight = 3;       // HIGH
                            } elseif ($distance === 2 || $distance === 3) {
                                $weight = 2;       // MEDIUM
                            } else {
                                $weight = 1;       // LOW
                            }

                            // ----- Phonetic hash (Metaphone) -----
                            $h = metaphone($canonicalKey);
                            if (!$h) continue;

                            $pfx = substr($h, 0, 5);
                            $hashPrefix = substr($this->twoLetterHash($h), 0, 2);

                            // ----- Shard directory -----
                            $shardDir = "$base/P/$hashPrefix";
                            File::ensureDirectoryExists($shardDir);

                            $fname = "$shardDir/$pfx.jsonl";

                            // ----- Phrase reference with weight -----
                            // ref = [nodefile, lineNumber]
                            // ------ Write ref + weight ------
                            $ref[2] = $weight;

                            $offset = $this->appendJsonLine($fname, $ref);
                            $this->appendIndexOffset($fname, $offset);

                            $shards["P/$hashPrefix"] = true;
                        }
                    }
                }

            }

            unset($terms);
        });

        return array_keys($shards);
    }

    /**
     * Load a shard file by prefix (T_/N_/P_) for a given domainId.
     * Uses generator to yield nodes one by one (memory-efficient).
     */

    public function loadShardRandom(?int $domainId, array $prefix, int $maxNodes = null): iterable
    {
        $base = $this->config['search']['tree_path'] . '/' . ($domainId ?? 'global');

        $dir = $prefix['dir'];   // e.g. "N/12"
        $file = $prefix['file']; // e.g. "12.jsonl"


        $jsonl = "$base/$dir/$file";
        $idx   = "$jsonl.idx";

        if (!file_exists($jsonl) || !file_exists($idx)) return;

        // Load offsets
        $offsets = array_filter(
            array_map('intval', explode(',', rtrim(file_get_contents($idx), ",")))
        );
        if (!$offsets) return;

        // Random sampling
        if ($maxNodes && $maxNodes < count($offsets)) {
            $keys = array_rand($offsets, $maxNodes);
            if (!is_array($keys)) $keys = [$keys];
            $offsets = array_intersect_key($offsets, array_flip($keys));
        }

        // Shuffle order to avoid bias
        shuffle($offsets);

        // Initialize mmap
        $mmap = new MmapFile($jsonl);
        $useMmap = $mmap->isMmap();

        // Fallback file handle
        $fh = null;
        if (!$useMmap) {
            $fh = fopen($jsonl, 'r');
            if (!$fh) return;
        }

        // ------------------------------
        // FAST PATH: Entire loop optimized
        // ------------------------------
        if ($useMmap) {

            foreach ($offsets as $offset) {
                $line = $mmap->readLineAt($offset);
                if (!$line) continue;

                $ref = json_decode($line, true);
                if (!$ref) continue;

                // ref = [nodeFile, offsetInNodeFile, optional weight]
                $weight = $ref[2] ?? 3;  // default weight = 1
                // Load the actual node from node file
                $nodeData = $this->loadNode($ref[0], $ref[1]);
                if (!$nodeData) continue;

                $nodeData['weight'] = $weight;   // attach weight

                yield $nodeData;
            }

            return;
        }

        // ------------------------------
        // SLOW PATH: fallback file reading
        // ------------------------------
        foreach ($offsets as $offset) {
            fseek($fh, $offset);
            $line = rtrim(fgets($fh), "\n");

            $ref = json_decode($line, true);
            if (!$ref) continue;

            // ref = [nodeFile, offsetInNodeFile, optional weight]
            $weight = $ref[2] ?? 3;  // default weight = 1
            // Load the actual node from node file
            $nodeData = $this->loadNode($ref[0], $ref[1]);
            if (!$nodeData) continue;

            $nodeData['weight'] = $weight;   // attach weight

            yield $nodeData;
        }

        fclose($fh);
    }




    /**
     * Optional: load all shards in a domain (fallback if too few candidates)
     */
    public function load(?int $domainId): iterable
    {
        $basePath = $this->config['search']['tree_path'] . '/' . ($domainId ?? 'global');
        if (!is_dir($basePath)) return;

        $files = scandir($basePath);
        foreach ($files as $file) {
            if (!str_ends_with($file, '.json')) continue;

            $handle = fopen("$basePath/$file", 'r');
            if (!$handle) continue;

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') continue;

                $node = json_decode($line, true);
                if ($node) yield $node;
            }

            fclose($handle);
        }
    }

    /**
     * Load a single node from a node file at a given offset.
     *
     * @param string $nodeFile  Path to the node file (JSONL)
     * @param int    $offset    Offset in the file (from index)
     * @return array|null       Decoded node or null if not found
     */
    protected function loadNode(string $nodeFile, int $offset): ?array
    {
        if (!file_exists($nodeFile)) {
            return null;
        }

        $fh = fopen($nodeFile, 'r');
        if (!$fh) {
            return null;
        }

        fseek($fh, $offset);
        $line = rtrim(fgets($fh), "\n");
        fclose($fh);

        if (!$line) return null;

        $node = json_decode($line, true);
        return $node ?: null;
    }


    /**
     * 2-letter stable hash for distributing nodes into 676 buckets
     */
    private function twoLetterHash(string $str): string
    {
        $h = crc32($str);
        $idx = $h % 676; // 26*26
        $a = intdiv($idx, 26);
        $b = $idx % 26;
        return chr(97 + $a) . chr(97 + $b); // aa, ab, ac, ... zz
    }

    /**
     * Append JSON line & return byte offset of the line in the file.
     */
    private function appendJsonLine(string $file, array $data): int
    {
        // Open node file in append mode
        $fh = fopen($file, 'a');
        if (!$fh) throw new \Exception("Cannot open file: $file");

        // Get current write byte position
        $offset = ftell($fh);

        // Write JSON line
        fwrite($fh, json_encode($data) . "\n");
        fclose($fh);

        return $offset;

    }

    private function appendIndexOffset(string $jsonlFile, int $offset): void
    {
        $idxFile = $jsonlFile . '.idx';

        $fh = fopen($idxFile, 'a+');
        fwrite($fh, $offset . ",");   // offset + comma
        fclose($fh);
    }

}