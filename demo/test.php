<?php

declare(strict_types=1);

function request(string $path, string $key, ?array $payload = null): array
{
    $options = ['method' => $payload === null ? 'GET' : 'POST',
        'header' => "X-Demo-Shop: $key\r\nContent-Type: application/json\r\n",
        'ignore_errors' => true, 'timeout' => 3];
    if ($payload !== null) {
        $options['content'] = json_encode($payload, JSON_THROW_ON_ERROR);
    }
    $body = file_get_contents('http://127.0.0.1:4301' . $path, false, stream_context_create(['http' => $options]));
    return [(int) explode(' ', $http_response_header[0])[1], json_decode($body, true, flags: JSON_THROW_ON_ERROR)];
}

function expect(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, "Failed: $label\n");
        exit(1);
    }
    echo "Passed: $label\n";
}

$sale = ['reference' => 'sale-1', 'receivedCents' => 10000];
expect(request('/api/shop', 'demo-a')[1]['available'] === 1, 'fresh fixture');
expect(request('/api/sales', 'demo-a', $sale)[0] === 201, 'partial sale confirmed');
expect(request('/api/sales', 'demo-a', $sale)[0] === 200, 'same reference is idempotent');
expect(request('/api/sales', 'demo-a', ['reference' => 'sale-2', 'receivedCents' => 10000])[0] === 409, 'last unit cannot be sold again');
[, $a] = request('/api/shop', 'demo-a');
expect($a['available'] === 0 && $a['cashCents'] === 10000 && $a['receivableCents'] === 20000, 'stock, cash, and receivable reconcile');
[, $b] = request('/api/shop', 'demo-b');
expect($b['available'] === 1 && $b['cashCents'] === 0, 'second shop is isolated');
