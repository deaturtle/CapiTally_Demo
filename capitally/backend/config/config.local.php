<?php
declare(strict_types=1);

// Local configuration for XAMPP. This file is git-ignored.
return [
    'app' => ['env' => 'development'],
    'db'  => [
        'host'     => '127.0.0.1',
        'name'     => 'capitally',
        'user'     => 'root',
        'password' => '',
    ],
    'backup' => [
        'mysqldump_path' => 'C:\\xampp\\mysql\\bin\\mysqldump.exe',
    ],
    'barcode_api' => [
        'enabled' => false,
        'api_key' => '',
    ],
];
