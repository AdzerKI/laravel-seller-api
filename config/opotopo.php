<?php

return [
    'url' => env('OPOTOPO_URL', 'https://api.opotopo.com'),
    'key' => env('OPOTOPO_API_KEY'),
    'organization_id' => env('OPOTOPO_ORGANIZATION_ID'),
    'publish' => (bool) env('OPOTOPO_PUBLISH', true),
    'batch' => 100,
    'timeout' => 60,
    'retry' => [
        'times' => 3,
        'sleep_ms' => 1000,
    ],
    'schedule' => env('OPOTOPO_SCHEDULE', '0 * * * *'),
    'catalogs' => [],
];
