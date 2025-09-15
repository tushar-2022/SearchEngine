🔎 Laravel Search Engine

A JSON-tree based fuzzy search engine for Laravel with:

Multi-domain support (separate indexes per domain),

Autocomplete (prefix suggestions),

Fuzzy search (handles typos like aple → apple),

Category filtering (by category IDs, not titles),

Configurable tree structure stored as JSON for super-fast lookups.

Supports dynamic model/column mapping so you can plug in any Term/Category models without code changes.

🚀 Installation

Require the package:

composer require tzar/search-engine


⚙️ Configuration

Publish the config:

php artisan vendor:publish --tag=search-config


This creates config/search.php:

return [

    'models' => [
        'term'     => App\Term::class,
        'category' => App\Category::class,
    ],

    'columns' => [
        'term_id'    => 'id',
        'term_title' => 'title',
        'term_type'  => 'type',      // optional, can be null
        'domain_id'  => 'domain_id', // set null if not used
    ],

    'search' => [
        'fuzzy_threshold'   => 2,
        'similarity_ratio'  => 40,
        'tree_path'         => storage_path('app/search/trees'),
        'branch_distance'   => 1,
        'min_candidates'    => 25,
        'token_mode'        => 'any',   // 'any' or 'all'
        'substring_min_len' => 3,
        'substring_boost'   => 20,
        'prefix_boost'      => 30,
        'return'            => 'ids',   // default return format
    ],

];

📖 Usage
Build the tree index

Per-domain (if domain_id column is configured):

Search::buildIndex(1);   // build for domain_id = 1
Search::buildIndex(2);   // build for domain_id = 2


Global (if domain_id is null in config):

Search::buildIndex(); // builds one tree_all.json

Autocomplete
$suggestions = Search::autocomplete('aple', 1);

// → [12, 15, 17]  (Term IDs)

Fuzzy Search
$results = Search::search('product', 1, [
    'category_ids' => [10, 12],
    'return'       => 'nodes', // full nodes instead of just IDs
    'limit'        => 15
]);

/*
[
  [
    "id" => 123,
    "title" => "Product with 4 Prems",
    "type" => "sku",
    "domain_id" => 1,
    "category_ids" => [10,12]
  ],
  ...
]
*/

🛠 Features

Phonetic tree branches: uses metaphone() to cluster similar words (so "aple" and "apple" share a branch).

Token + phrase indexing: splits by tokens for flexible matches ("product with 4 prems" found when searching "product").

Configurable fuzzy matching: tune similarity %, Levenshtein distance, substring/prefix boosts.

Fast JSON lookups: indexes stored as JSON in storage/app/search/trees/.

📂 JSON Tree Format

Each branch groups together similar terms:

{
  "T:APPL": [
    {
      "id": 12,
      "title": "Apple",
      "type": "fruit",
      "domain_id": 1,
      "category_ids": [2, 5]
    },
    {
      "id": 15,
      "title": "Maple",
      "type": "tree",
      "domain_id": 1,
      "category_ids": [7]
    }
  ]
}

📌 Roadmap

🔄 Optional Elasticsearch driver for larger datasets.

🗄 Artisan command php artisan search:rebuild for scheduled rebuilds.

🚀 Multi-language phonetic clustering.

📜 License

MIT © 2025 — tzar/search-engine
