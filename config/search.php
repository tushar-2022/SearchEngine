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
        'category' => App\Models\Category::class, // optional, only if using category filters
    ],

    /*
    |--------------------------------------------------------------------------
    | Column mappings (dynamic)
    |--------------------------------------------------------------------------
    | Set column names used by package. If 'domain_id' is set to null
    | the package will build a single global tree (tree_all.json).
    */
    'columns' => [
        'term_id'    => 'id',  // primary key (can be uuid in some cases)
        'term_title' => 'title', // main searchable column 'title' in given model
        'term_type'  => 'type',      // dynamic column for type (can be null)
        'domain_id'  => 'domain_id', // set null if not used
    ],

    /*
    |--------------------------------------------------------------------------
    | Fuzzy / Tree options
    |--------------------------------------------------------------------------
    */
    'search' => [
      // --- Fuzzy & Similarity Controls ---
      'fuzzy_threshold'        => 1,    // max allowed edit distance between query and title/token
      'similarity_ratio'       => 45,   // minimum % match (from similar_text or Jaro-Winkler)
      'max_len_diff_distance'  => 4,    // skip Levenshtein if word length difference > N
      'distance_cutoff'        => 3,    // skip results with distance > N (for fuzzy filtering)
      'branch_distance'        => 1,    // load nearby branches up to this distance
      'use_fast_distance'      => true, // use Levenshtein+swap heuristic instead of full Damerau

      // --- Token & Candidate Management ---
      'min_candidates'         => 5,   // fallback to full tree if fewer than this many found
      'token_mode'             => 'any',// 'any' or 'all' tokens must appear in title
      'token_cache_limit'      => 2000, // limit for in-memory cache of token scores

      // --- Boost & Weight Tuning ---
      'substring_min_len'      => 3,    // minimum length for substring boosting
      'substring_boost'        => 15,   // boost when query appears as substring
      'prefix_boost'           => 35,   // boost when query matches prefix
      'word_boost'             => 25,   // boost for full-token match
      'phrase_boost'           => 30,   // bonus when tokens appear in same order
      'all_tokens_boost'       => 40,   // extra score when all tokens found
      'missing_token_penalty'  => -50,  // penalty for missing tokens

      // --- Caching & Storage ---
      'use_msgpack'            => false, // use msgpack for smaller shard files
      'tree_path'              => storage_path('app/search/trees'),
      'cache_ttl'              => 3600, // seconds for shard cache lifespan
      'in_memory_shard_cache'  => true, // keep shard data cached during request

      // --- Performance & Memory ---
      'early_exit_threshold'   => 20,   // if cumulative score drops below, stop evaluating
      'max_comparisons'        => 1000, // hard cap per search (avoid runaway loops)
      'return'                 => 'ids',// 'ids' (default) or 'nodes'
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
