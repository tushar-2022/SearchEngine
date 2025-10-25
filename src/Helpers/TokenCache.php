<?php

namespace Tzart\SearchEngine\Helpers;

use Illuminate\Support\Facades\Cache;

class TokenCache
{
    private int $ttl;

    /**
     * Constructor
     *
     * @param int $ttl Cache duration in seconds (default 5 minutes)
     */
    public function __construct(int $ttl = 300)
    {
        $this->ttl = $ttl;
    }

    /**
     * Get cached value or compute it using Laravel cache
     *
     * @param string $token
     * @param string $titleLower
     * @param array $titleWords
     * @param callable $compute Callback to compute value if not cached
     * @return array
     */
    public function get(string $token, string $titleLower, array $titleWords, callable $compute): array
    {
        // Unique key for token + title
        $key = 'token_cache|' . md5($token . '|' . $titleLower);

        // Laravel cache handles driver (APCu, Redis, file, etc.)
        return Cache::remember($key, $this->ttl, function () use ($token, $titleLower, $titleWords, $compute) {
            return $compute($token, $titleLower, $titleWords);
        });
    }

    /**
     * Clear a single token from cache
     */
    public function forget(string $token, string $titleLower): void
    {
        $key = 'token_cache|' . md5($token . '|' . $titleLower);
        Cache::forget($key);
    }

    /**
     * Flush all token caches
     */
    public function flushAll(): void
    {
        Cache::flush();
    }
}
