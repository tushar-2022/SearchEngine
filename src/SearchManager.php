<?php

namespace Tzart\SearchEngine;

use Illuminate\Support\Arr;
use Tzart\SearchEngine\Drivers\JsonSearchDriver;

class SearchManager
{
    protected array $config;
    protected array $drivers = [];
    protected string $defaultDriver;

    public function __construct()
    {
        $this->config = config('search');
        $this->defaultDriver = $this->config['default'] ?? 'json';
    }

    /**
     * Get driver instance (singleton per type)
     */
    public function driver(?string $name = null): JsonSearchDriver
    {
        $name = $name ?? $this->defaultDriver;

        if (!isset($this->drivers[$name])) {
            $driverConfig = $this->config['drivers'][$name] ?? [];
            $fullConfig = array_merge($this->config, ['driver_config' => $driverConfig]);

            switch ($name) {
                case 'json':
                    $this->drivers[$name] = new JsonSearchDriver($fullConfig);
                    break;

                // future drivers: meilisearch, elasticsearch
                // case 'meilisearch': ...
                // case 'elasticsearch': ...
                
                default:
                    throw new \InvalidArgumentException("Search driver [$name] not supported.");
            }
        }

        return $this->drivers[$name];
    }

    /**
     * Build search index
     */
    public function buildIndex(?int $domainId = null): void
    {
        $this->driver()->buildIndex($domainId);
    }

    /**
     * Perform search
     */
    public function search(string $query, ?int $domainId = null, array $options = []): array
    {
        return $this->driver()->search($query, $domainId, $options);
    }

    /**
     * Perform autocomplete
     */
    public function autocomplete(string $query, ?int $domainId = null, array $options = []): array
    {
        return $this->driver()->autocomplete($query, $domainId, $options);
    }

    /**
     * Allow dynamic calls to driver
     */
    public function __call($method, $parameters)
    {
        $driver = $this->driver();
        if (method_exists($driver, $method)) {
            return $driver->$method(...$parameters);
        }

        throw new \BadMethodCallException("Method [$method] does not exist on SearchManager or driver.");
    }
}
