<?php

use App\Geo\GeoServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\PassportServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    PassportServiceProvider::class,

    // pf-geo: fork feature, see docs/fork/GEO_FEED.md
    GeoServiceProvider::class,
];
