<?php

namespace Tzar\SearchEngine;

use Illuminate\Support\ServiceProvider;

class SearchServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/search.php', 'search');

        $this->app->singleton(SearchManager::class, function ($app) {
            return new SearchManager();
        });

        $this->app->alias(SearchManager::class, 'search.manager');
    }

    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/search.php' => config_path('search.php'),
        ], 'config');
    }
}
