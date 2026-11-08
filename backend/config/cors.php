<?php

return [
    'paths' => ['api/v1/constructor/catalog', 'api/v1/orders', 'api/v1/log/*'],
    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],
    'allowed_origins' => ['https://namenecklace.ru'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Content-Type'],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => false,
];
