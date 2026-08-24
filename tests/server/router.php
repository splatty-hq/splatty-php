<?php

declare(strict_types=1);

// Records each request so the transport test can assert on what went over the
// wire. Used only by the PHP built-in server started in TransportTest.
$dir = getenv('SPLATTY_TEST_DIR') ?: sys_get_temp_dir();

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with((string) $key, 'HTTP_') || $key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
        $headers[$key] = $value;
    }
}

file_put_contents(
    $dir . '/req-' . microtime(true) . '-' . bin2hex(random_bytes(4)) . '.json',
    json_encode([
        'path' => $_SERVER['REQUEST_URI'] ?? '',
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'headers' => $headers,
        'body' => base64_encode((string) file_get_contents('php://input')),
    ], JSON_UNESCAPED_SLASHES),
);

// A path containing "fail" lets a test drive the non-2xx branch.
http_response_code(str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), 'fail') ? 500 : 202);
