<?php

namespace Tzart\SearchEngine\Facades;

use Illuminate\Support\Facades\Facade;

class Search extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Vendor\Search\SearchManager::class;
    }
}
