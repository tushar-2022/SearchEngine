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
     * Build all shards for a domain (writes JSON or msgpack files)
     */
    public function build(?int $domainId = null): array
    {
      $query = $this->termModel::query();
      if ($domainId && $this->columns['domain_id']) {
          $query->where($this->columns['domain_id'], $domainId);
      }

      $shards = [];
      $query->with('categories')->chunk(300, function ($terms) use (&$shards) {
        foreach ($terms as $term) {
          $title = $term->{$this->columns['term_title']};
          $id = $term->{$this->columns['term_id']};
          $type = $this->columns['term_type'] ? $term->{$this->columns['term_type']} : null;

          $phonetic = metaphone(strtolower($title));
          $prefix = substr($phonetic, 0, 2);

          $categories = $term->categories->pluck($this->columns['category_id'] ?? 'id')->all();

          // Split title into words (remove punctuation and extra spaces)
          $words = preg_split('/\s+/', trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', strtolower($title))));

          $node = [
              'id' => $id,
              't' => $title,
              'y' => $type,
              'c' => $categories,
          ];

          // For each word, create a shard entry
          foreach ($words as $word) {
              if (empty($word)) {
                  continue;
              }

              $phonetic = metaphone($word);
              $prefix = substr($phonetic, 0, 2);

              // Add full phonetic hash to node (optional per word)
              $nodeWithPhonetic = $node + ['h' => $phonetic];

              $shards[$prefix][] = $nodeWithPhonetic;
          }
        }

        // Optional: free memory between chunks
        unset($terms);
      });


      $basePath = $this->config['search']['tree_path'] . '/' . ($domainId ?? 'global');
      File::ensureDirectoryExists($basePath);

      foreach ($shards as $prefix => $nodes) {
        $path = "$basePath/{$prefix}.json";
        $data = !empty($this->config['search']['use_msgpack']) ? msgpack_pack($nodes) : json_encode($nodes);
        file_put_contents($path, $data);
      }

      return $shards;
    }

    /**
     * Load all shards for a domain (memory-heavy)
     */
    public function load(?int $domainId = null): array
    {
        $basePath = $this->config['search']['tree_path'] . '/' . ($domainId ?? 'global');
        if (!is_dir($basePath)) return [];

        $tree = [];
        foreach (glob("$basePath/*.json") as $file) {
            $prefix = basename($file, '.json');
            $data = file_get_contents($file);
            $tree[$prefix] = !empty($this->config['search']['use_msgpack']) ? msgpack_unpack($data) : json_decode($data, true);
        }

        return $tree;
    }

    /**
     * Load a single shard (lazy, memory-friendly)
     */
    public function loadShard(?int $domainId, string $prefix): array
    {
        $basePath = $this->config['search']['tree_path'] . '/' . ($domainId ?? 'global');
        $file = "$basePath/{$prefix}.json";
        if (!file_exists($file)) return [];

        $raw = file_get_contents($file);
        return !empty($this->config['search']['use_msgpack']) ? msgpack_unpack($raw) : json_decode($raw, true);
    }
}