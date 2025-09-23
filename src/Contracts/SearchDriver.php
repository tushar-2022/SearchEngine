<?php

namespace Tzart\SearchEngine\Contracts;

interface SearchDriver
{
    /**
     * Build the JSON tree index (per-domain if domainId provided).
     *
     * @param int|null $domainId
     * @return void
     */
    public function buildIndex(?int $domainId = null): void;

    /**
     * Autocomplete (fast suggestions).
     *
     * @param string $query
     * @param int|null $domainId
     * @param array $options
     * @return array
     */
    public function autocomplete(string $query, ?int $domainId = null, array $options = []): array;

    /**
     * Full fuzzy search.
     *
     * @param string $query
     * @param int|null $domainId
     * @param array $options
     * @return array
     */
    public function search(string $query, ?int $domainId = null, array $options = []): array;
}
