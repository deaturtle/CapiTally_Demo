<?php
declare(strict_types=1);

// Copy this file to config.local.php and edit. config.local.php is git-ignored.
return [
    'app' => ['env' => 'development'],
    'db'  => [
        'host'     => '127.0.0.1',
        'name'     => 'capitally',
        'user'     => 'root',
        'password' => '',
    ],
    'barcode_api' => [
        'enabled' => false,
        'api_key' => '',
    ],
];
