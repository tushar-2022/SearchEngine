<?php

namespace Tzart\SearchEngine\Drivers;

use Tzart\SearchEngine\Contracts\SearchDriver;
use Elasticsearch\ClientBuilder;

class ElasticSearchDriver implements SearchDriver
{
    protected $client;
    protected string $indexName;

    public function __construct(array $config)
    {
        $this->client = ClientBuilder::create()->setHosts($config['hosts'])->build();
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

        foreach ($data as $doc) {
            $this->client->index([
                'index' => $this->indexName,
                'id'    => $doc['id'],
                'body'  => $doc,
            ]);
        }
    }

    public function autocomplete(string $query, ?int $domainId = null, array $options = []): array
    {
        return $this->performSearch($query, $domainId, $options, 10, true);
    }

    public function search(string $query, ?int $domainId = null, array $options = []): array
    {
        return $this->performSearch($query, $domainId, $options, 20, false);
    }

    protected function performSearch(string $query, ?int $domainId, array $options, int $defaultLimit, bool $isAutocomplete): array
    {
        $must = [];

        if ($isAutocomplete) {
            $must[] = ['match_phrase_prefix' => ['title' => $query]];
        } else {
            $must[] = [
                'multi_match' => [
                    'query'     => $query,
                    'fields'    => ['title^2'],
                    'fuzziness' => 'AUTO',
                ],
            ];
        }

        if ($domainId) {
            $must[] = ['term' => ['domain_id' => $domainId]];
        }
        if (!empty($options['category_ids'])) {
            $must[] = ['terms' => ['category_ids' => $options['category_ids']]];
        }

        $params = [
            'index' => $this->indexName,
            'body'  => [
                'size'  => $options['limit'] ?? $defaultLimit,
                'query' => ['bool' => ['must' => $must]],
            ],
        ];

        $results = $this->client->search($params);
        return array_column($results['hits']['hits'], '_source');
    }
}
