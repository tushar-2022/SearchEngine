<?php

namespace Tzar\SearchEngine;

use Illuminate\Support\Facades\File;

class TreeBuilder
{
    protected $config;
    protected $termModel;
    protected $categoryModel;
    protected $columns;

    public function __construct(array $config)
    {
        $this->config        = $config;
        $this->termModel     = $config['models']['term'];
        $this->categoryModel = $config['models']['category'];
        $this->columns       = $config['columns'];
    }

    public function build($domainId = null)
    {
        $query = $this->termModel::query();

        if ($domainId && $this->columns['domain_id']) {
            $query->where($this->columns['domain_id'], $domainId);
        }

        $terms = $query->with('categories')->get();

        $shards = [];

        foreach ($terms as $term) {
            $title = $term->{$this->columns['term_title']};
            $id    = $term->{$this->columns['term_id']};
            $type  = $this->columns['term_type']
                ? $term->{$this->columns['term_type']}
                : null;

            $phonetic = metaphone(strtolower($title));
            $prefix   = substr($phonetic, 0, 2);

            $categories = $term->categories->pluck(
                $this->columns['category_id']
            )->all();

            $node = [
                'id' => $id,
                't'  => $title,
                'y'  => $type,
                'c'  => $categories,
                'h'  => $phonetic,
            ];

            $shards[$prefix][] = $node;
        }

        $basePath = $this->config['search']['tree_path'] . '/' . ($domainId ?? 'global');
        File::ensureDirectoryExists($basePath);

        foreach ($shards as $prefix => $nodes) {
            $path = "$basePath/{$prefix}.json";

            $data = $this->config['search']['use_msgpack']
                ? msgpack_pack($nodes)
                : json_encode($nodes);

            file_put_contents($path, $data);
        }
    }
}
