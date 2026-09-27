<?php

return [
    'driver' => 'private-database',
    // Storage lifetime is not the authenticated idle deadline (checked separately).
    'lifetime' => 480,
    'expire_on_close' => true,
    'encrypt' => true,
    'serialization' => 'json',
    'connection' => 'pgsql',
    'table' => 'sessions',
    'lottery' => [0, 100],
    'cookie' => 'it_requests_session',
    'path' => '/', 'domain' => null,
    'secure' => env('APP_ENV', 'production') === 'production' ? true : (bool) env('SESSION_SECURE_COOKIE', true),
    'http_only' => true, 'same_site' => 'lax', 'partitioned' => false,
    'block' => true,
];
