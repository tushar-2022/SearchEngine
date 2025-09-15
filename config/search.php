<?php

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Search Driver
    |--------------------------------------------------------------------------
    */
    'default' => env('SEARCH_DRIVER', 'json'),

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    */
    'models' => [
        // set your app models here
        'term'     => App\Models\Term::class,
        'category' => App\Models\Category::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Column mappings (dynamic)
    |--------------------------------------------------------------------------
    | Set column names used by package. If 'domain_id' is set to null
    | the package will build a single global tree (tree_all.json).
    */
    'columns' => [
        'term_id'    => 'id',
        'term_title' => 'title',
        'term_type'  => 'type',      // dynamic column for type (can be null)
        'domain_id'  => 'domain_id', // set null if not used
    ],

    /*
    |--------------------------------------------------------------------------
    | Fuzzy / Tree options
    |--------------------------------------------------------------------------
    */
    'search' => [
        'fuzzy_threshold'   => 2,      // max levenshtein distance (title vs query)
        'similarity_ratio'  => 40,     // % threshold from similar_text
        'tree_path'         => storage_path('app/search/trees'),
        'branch_distance'   => 1,      // levenshtein distance for nearby branch keys
        'min_candidates'    => 25,     // if fewer candidates, expand to nearby branches
        'token_mode'        => 'any',  // 'any' or 'all' tokens must be present
        'substring_min_len' => 3,      // substring boost enabled when query len >= N
        'substring_boost'   => 20,     // similarity percent added for substring matches
        'prefix_boost'      => 30,     // similarity percent added for prefix/autocomplete
        'return'            => 'ids',  // default return: 'ids' or 'nodes'
    ],
    /*
    |--------------------------------------------------------------------------
    | Driver Connections
    |--------------------------------------------------------------------------
    */
    'drivers' => [
        'json' => [
            'storage_path' => storage_path('search_trees'),
        ],
        'meilisearch' => [
            'host'  => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
            'key'   => env('MEILISEARCH_KEY'),
            'index' => 'terms',
        ],
        'elasticsearch' => [
            'hosts' => [env('ELASTIC_HOST', 'http://127.0.0.1:9200')],
            'index' => 'terms',
        ],
    ],

];
