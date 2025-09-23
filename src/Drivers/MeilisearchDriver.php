<?php

namespace Tzart\SearchEngine\Drivers;

use Tzart\SearchEngine\Contracts\SearchDriver;
use Meilisearch\Client;

class MeilisearchDriver implements SearchDriver
{
    protected Client $client;
    protected string $indexName;

    public function __construct(array $config)
    {
        $this->client = new Client($config['host'], $config['key'] ?? null);
        $this->indexName = $config['index'] ?? 'terms';
    }

    public function buildIndex(string $modelClass, ?int $domainId = null, array $options = []): void
    {
        $query = $modelClass::query()->with('categories');

        if ($domainId && $modelClass::getConnection()->getSchemaBuilder()->hasColumn($modelClass::getTable(), 'domain_id')) {
            $query->where('domain_id', $domainId);
        }

        $data = $query->get()->map(function ($item) {
            return [
                'id'           => $item->id,
                'title'        => $item->title,
                'type'         => $item->type ?? null,
                'domain_id'    => $item->domain_id ?? null,
                'category_ids' => $item->categories->pluck('id')->toArray(),
            ];
        })->toArray();

        $index = $this->client->index($this->indexName);
        $index->addDocuments($data);

        $index->updateSearchableAttributes(['title']);
        $index->updateFilterableAttributes(['domain_id', 'category_ids']);
    }

    public function autocomplete(string $query, ?int $domainId = null, array $options = []): array
    {
        return $this->performSearch($query, $domainId, $options, 10);
    }

    public function search(string $query, ?int $domainId = null, array $options = []): array
    {
        return $this->performSearch($query, $domainId, $options, 20);
    }

    protected function performSearch(string $query, ?int $domainId, array $options, int $defaultLimit): array
    {
        $filters = [];

        if ($domainId) {
            $filters[] = "domain_id = $domainId";
        }
        if (!empty($options['category_ids'])) {
            $catFilters = implode(' OR ', array_map(fn($id) => "category_ids = $id", $options['category_ids']));
            $filters[] = "($catFilters)";
        }

        $search = $this->client->index($this->indexName)->search($query, [
            'limit'  => $options['limit'] ?? $defaultLimit,
            'filter' => $filters ? implode(' AND ', $filters) : null,
        ]);

        return $search->getHits();
    }
}
