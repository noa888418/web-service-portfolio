<?php

return [
    'default' => 'database',
    'stores' => ['database' => [
        'driver' => 'database', 'connection' => 'pgsql', 'table' => 'cache',
        'lock_connection' => 'pgsql', 'lock_table' => 'cache_locks',
    ]],
    'prefix' => 'it_requests_',
];
