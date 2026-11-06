<?php

declare(strict_types=1);

function respond(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
if ($path === '/health' && $method === 'GET') {
    respond(200, ['status' => 'ok', 'mode' => 'local-fixture']);
}

$shop = match ($_SERVER['HTTP_X_DEMO_SHOP'] ?? '') {
    'demo-a' => 'shop-a',
    'demo-b' => 'shop-b',
    default => null,
};
if ($shop === null) {
    respond(401, ['error' => 'invalid_demo_shop']);
}
if (!in_array([$method, $path], [['GET', '/api/shop'], ['POST', '/api/sales']], true)) {
    respond(404, ['error' => 'not_found']);
}

$db = new PDO('sqlite:' . (getenv('GMPE_DEMO_DB') ?: __DIR__ . '/gmpe-demo.sqlite'));
$db->exec('PRAGMA busy_timeout = 5000');
$db->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS stock (shop TEXT PRIMARY KEY, available INTEGER NOT NULL CHECK (available >= 0));
    CREATE TABLE IF NOT EXISTS sales (
        shop TEXT NOT NULL REFERENCES stock(shop),
        reference TEXT NOT NULL,
        received INTEGER NOT NULL CHECK (received BETWEEN 0 AND 30000),
        PRIMARY KEY (shop, reference)
    );
    INSERT OR IGNORE INTO stock (shop, available) VALUES ('shop-a', 1), ('shop-b', 1);
    SQL);

if ($method === 'GET') {
    $query = $db->prepare('SELECT available, (SELECT COALESCE(SUM(received), 0) FROM sales WHERE shop = stock.shop) AS cashCents,
        (SELECT COALESCE(SUM(30000 - received), 0) FROM sales WHERE shop = stock.shop) AS receivableCents FROM stock WHERE shop = ?');
    $query->execute([$shop]);
    respond(200, ['shop' => $shop, ...$query->fetch(PDO::FETCH_ASSOC)]);
}

$payload = json_decode(file_get_contents('php://input', false, null, 0, 4097), true);
$reference = $payload['reference'] ?? null;
$received = $payload['receivedCents'] ?? null;
if (!is_string($reference) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $reference)
    || !is_int($received) || $received < 0 || $received > 30000) {
    respond(422, ['error' => 'invalid_sale']);
}

$db->exec('BEGIN IMMEDIATE');
try {
    $lookup = $db->prepare('SELECT received FROM sales WHERE shop = ? AND reference = ?');
    $lookup->execute([$shop, $reference]);
    $previous = $lookup->fetchColumn();
    if ($previous !== false) {
        $db->exec('COMMIT');
        if ((int) $previous !== $received) {
            respond(409, ['error' => 'reference_conflict']);
        }
        respond(200, ['reference' => $reference, 'receivedCents' => $received, 'receivableCents' => 30000 - $received]);
    }

    $decrement = $db->prepare('UPDATE stock SET available = available - 1 WHERE shop = ? AND available > 0');
    $decrement->execute([$shop]);
    if ($decrement->rowCount() !== 1) {
        $db->exec('ROLLBACK');
        respond(409, ['error' => 'stock_unavailable']);
    }

    $insert = $db->prepare('INSERT INTO sales (shop, reference, received) VALUES (?, ?, ?)');
    $insert->execute([$shop, $reference, $received]);
    $db->exec('COMMIT');
    respond(201, ['reference' => $reference, 'receivedCents' => $received, 'receivableCents' => 30000 - $received]);
} catch (Throwable $error) {
    $db->exec('ROLLBACK');
    error_log($error->getMessage());
    respond(500, ['error' => 'sale_failed']);
}
