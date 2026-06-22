<?php

declare(strict_types=1);

$dir = dirname(__DIR__) . '/config/jwt';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

$config = [
    'digest_alg' => 'sha256',
    'private_key_bits' => 4096,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
];

$res = openssl_pkey_new($config);
if ($res === false) {
    fwrite(STDERR, openssl_error_string() . PHP_EOL);
    exit(1);
}

openssl_pkey_export($res, $privateKey);
$details = openssl_pkey_get_details($res);

file_put_contents($dir . '/private.pem', $privateKey);
file_put_contents($dir . '/public.pem', $details['key']);

echo "JWT keys generated in {$dir}\n";
