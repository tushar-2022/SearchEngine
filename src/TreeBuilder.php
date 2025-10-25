<?php

namespace Tzart\SearchEngine;

use Illuminate\Support\Facades\File;

class TreeBuilder
{
    protected array $config;
    protected string $termModel;
    protected ?string $categoryModel;
    protected array $columns;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->termModel = $config['models']['term'];
        $this->categoryModel = $config['models']['category'] ?? null;
        $this->columns = $config['columns'];
    }

    /**
     * Build all shards for a domain (writes JSON files incrementally)
     */
    public function build(?int $domainId = null): array
    {
        $query = $this->termModel::query();
        if ($domainId && $this->columns['domain_id']) {
            $query->where($this->columns['domain_id'], $domainId);
        }

        $basePath = $this->config['search']['tree_path'] . '/' . ($domainId ?? 'global');
        File::ensureDirectoryExists($basePath);

        $shards = [];

        $query->with('categories')->chunk(300, function ($terms) use (&$basePath, &$shards) {
            foreach ($terms as $term) {
                $title = $term->{$this->columns['term_title']};
                $id = $term->{$this->columns['term_id']};
                $type = $this->columns['term_type'] ? $term->{$this->columns['term_type']} : null;

                $categories = $term->categories->pluck($this->columns['category_id'] ?? 'id')->all();

                $node = [
                    'id' => $id,
                    't' => $title,
                    'y' => $type,
                    'c' => $categories,
                ];

                // Normalize title and split into words
                $cleanTitle = preg_replace('/[^\p{L}\p{N}\s]/u', '', strtolower($title));
                $words = preg_split('/\s+/', trim($cleanTitle));

                foreach ($words as $word) {
                    if (!$word) continue;

                    if (ctype_digit($word)) {
                        // --- Numeric shard (N:) ---
                        $prefix = 'N_' . substr($word, 0, 2);
                        $path = "$basePath/{$prefix}.json";
                        file_put_contents($path, json_encode($node) . PHP_EOL, FILE_APPEND);
                        $shards[$prefix] = true;
                    } else {
                        // --- Text shard (T:) ---
                        $phonetic = metaphone($word);
                        if ($phonetic) {
                            $prefix = 'T_' . substr($phonetic, 0, 3);
                            $nodeWithPhonetic = $node + ['h' => $phonetic];
                            $path = "$basePath/{$prefix}.json";
                            file_put_contents($path, json_encode($nodeWithPhonetic) . PHP_EOL, FILE_APPEND);
                            $shards[$prefix] = true;
                        }
                    }
                }

                // --- Phrase-level shard (P:) ---
                if (count($words) > 1) {
                    $phrasePhonetic = metaphone(implode(' ', $words));
                    if ($phrasePhonetic) {
                        $prefix = 'P_' . substr($phrasePhonetic, 0, 3);
                        $path = "$basePath/{$prefix}.json";
                        file_put_contents($path, json_encode($node + ['h' => $phrasePhonetic]) . PHP_EOL, FILE_APPEND);
                        $shards[$prefix] = true;
                    }
                }
            }

            unset($terms); // free memory
        });

        return array_keys($shards); // returns list of shard prefixes created
    }


    /**
     * Load a shard file by prefix (T_/N_/P_) for a given domainId.
     * Uses generator to yield nodes one by one (memory-efficient).
     */
    public function loadShard(?int $domainId, string $prefix, int $maxNodes = null): iterable
    {
        $basePath = $this->config['search']['tree_path'] . '/' . ($domainId ?? 'global');
        $file = "$basePath/" . strtoupper($prefix) . ".json";

        if (!file_exists($file)) return;

        $handle = fopen($file, 'r');
        if (!$handle) return;

        $count = 0;
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') continue;

            $node = json_decode($line, true);
            if ($node) yield $node;

            $count++;
            if ($maxNodes && $count >= $maxNodes) break;
        }

        fclose($handle);
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
}