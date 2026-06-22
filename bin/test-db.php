<?php

$dsns = [
    'pgsql:host=127.0.0.1;port=5432;dbname=postgres' => ['postgres', 'postgres'],
    'pgsql:host=127.0.0.1;port=5432;dbname=postgres' => ['app', '!ChangeMe!'],
];

foreach ($dsns as $dsn => [$user, $pass]) {
    try {
        new PDO($dsn, $user, $pass);
        echo "OK: {$user}\n";
        exit(0);
    } catch (Throwable $e) {
        echo "FAIL {$user}: {$e->getMessage()}\n";
    }
}

exit(1);
