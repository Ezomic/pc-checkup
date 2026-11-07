<?php

declare(strict_types=1);

/*
 * A router for `php -S` that answers the pairing and scan endpoints the way PC Checkup does, from
 * the scenario.json in PCC_FAKE_DIR, and writes what it was sent next to it.
 */

$dir = (string) getenv('PCC_FAKE_DIR');
/** @var array{start: array{status: int, body: array<string, mixed>}, qr_ascii: list<string>, polls: list<array{status: int, body: array<string, mixed>}>, upload: array{status: int, body: array<string, mixed>}, poll_delay?: int} $scenario */
$scenario = json_decode((string) file_get_contents($dir.'/scenario.json'), true);
$method = (string) $_SERVER['REQUEST_METHOD'];
$uri = (string) $_SERVER['REQUEST_URI'];
$path = (string) parse_url($uri, PHP_URL_PATH);

file_put_contents($dir.'/requests.log', json_encode([
    'method' => $method,
    'uri' => $uri,
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
])."\n", FILE_APPEND);

/**
 * @param  array{status: int, body: array<string, mixed>}  $reply
 */
function fakeAnswer(array $reply): void
{
    http_response_code($reply['status']);
    header('Content-Type: application/json');
    echo json_encode($reply['body']);
}

if ($method === 'POST' && $path === '/scripts/pair') {
    $start = $scenario['start'];

    if (($_GET['qr'] ?? '') === 'ascii') {
        $start['body']['qr'] = $scenario['qr_ascii'];
    }

    fakeAnswer($start);
} elseif ($method === 'GET' && str_starts_with($path, '/scripts/pair/')) {
    $count = is_file($dir.'/polls') ? (int) file_get_contents($dir.'/polls') : 0;
    file_put_contents($dir.'/polls', (string) ($count + 1));
    sleep($scenario['poll_delay'] ?? 0);
    fakeAnswer($scenario['polls'][min($count, count($scenario['polls']) - 1)]);
} elseif ($method === 'POST' && $path === '/api/scans') {
    file_put_contents($dir.'/upload.json', (string) file_get_contents('php://input'));
    fakeAnswer($scenario['upload']);
} else {
    http_response_code(404);
}
