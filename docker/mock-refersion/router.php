<?php

/**
 * Stand-in for the three Refersion v2026-08 endpoints the app uses, so it runs without
 * real credentials: php -S 0.0.0.0:8080 docker/mock-refersion/router.php
 *
 * The account is deterministic (237 affiliates, 3 pages at limit 100) and includes the
 * cases the app must handle: near-miss flag values, no flag, company-only names, a tie,
 * an affiliate without performance data, and top earners that are not eligible.
 */

declare(strict_types=1);

const API = '/v2026-08';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($method === 'POST' && $path === '/oauth/token') {
    $ok = ($_POST['client_id'] ?? null) === (getenv('MOCK_CLIENT_ID') ?: 'mock-client-id')
        && ($_POST['client_secret'] ?? null) === (getenv('MOCK_CLIENT_SECRET') ?: 'mock-client-secret');

    $ok ? respond(200, ['token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => 'mock-token-'.bin2hex(random_bytes(8))])
        : respond(401, ['error' => 'invalid_client']);
}

if (! str_starts_with($_SERVER['HTTP_AUTHORIZATION'] ?? '', 'Bearer mock-token-')) {
    respond(401, ['error' => 'Authentication required']);
}

if ($method === 'GET' && $path === API.'/affiliates') {
    $page = (int) ($_GET['page'] ?? 1);
    $limit = (int) ($_GET['limit'] ?? 10);

    if ($page < 1 || $limit < 1 || $limit > 100) {
        respond(422, ['error' => 'The page must be at least 1 and the limit between 1 and 100.']);
    }

    respond(200, ['data' => array_slice(array_column(dataset(), 'record'), ($page - 1) * $limit, $limit)]);
}

if ($method === 'GET' && preg_match('#^'.API.'/affiliates/(\d+)/custom-fields$#', $path, $m)) {
    $affiliate = dataset()[(int) $m[1]] ?? respond(404, ['error' => 'Affiliate not found']);

    respond(200, ['data' => $affiliate['fields']]);
}

respond(404, ['error' => 'Not found']);

/** @return array<int, array{record: array<string, mixed>, fields: list<array<string, mixed>>}> */
function dataset(): array
{
    mt_srand(20261111);

    $first = ['Ava', 'Ben', 'Chloe', 'Diego', 'Elena', 'Farah', 'Gabriel', 'Hana', 'Ivan', 'Julia', 'Kenji', 'Lara', 'Mateo', 'Nora', 'Omar', 'Priya', 'Quinn', 'Rafael', 'Sofia', 'Tariq', 'Uma', 'Victor', 'Wen', 'Ximena', 'Yusuf', 'Zoe'];
    $last = ['Almeida', 'Brooks', 'Chen', 'Dubois', 'Evans', 'Fischer', 'Garcia', 'Haddad', 'Ito', 'Johansson', 'Kowalski', 'Lopez', 'Moreau', 'Nakamura', 'Okafor', 'Petrov', 'Rossi', 'Silva', 'Tanaka', 'Weber'];
    // Mostly TRUE/FALSE, plus near-misses the strict rule must reject (null = no field).
    $flags = ['TRUE', 'TRUE', 'TRUE', 'TRUE', 'FALSE', 'FALSE', 'FALSE', null, null, 'true', ' TRUE', 'True'];

    $data = [];
    for ($i = 1; $i <= 237; $i++) {
        $id = 8000000 + $i * 37;
        $data[$id] = [
            'record' => [
                'id' => $id,
                'status' => 'APPROVED',
                'firstName' => $first[mt_rand(0, count($first) - 1)],
                'lastName' => $last[mt_rand(0, count($last) - 1)],
                'company' => null,
                'performance' => ['total' => ['commission' => mt_rand(0, 2500000) / 100]],
            ],
            'flag' => $flags[mt_rand(0, count($flags) - 1)],
        ];
    }

    $ids = array_keys($data);
    $byCommission = $ids;
    usort($byCommission, fn ($a, $b) => $data[$b]['record']['performance']['total']['commission'] <=> $data[$a]['record']['performance']['total']['commission']);

    // The top earners are not eligible, so the early stop has to walk past the first batch.
    foreach (['true', null, ' TRUE', 'FALSE', 'TRUE', 'True'] as $rank => $flag) {
        $data[$byCommission[$rank]]['flag'] = $flag;
    }

    // An eligible leader with only a company name.
    $data[$byCommission[4]]['record'] = ['firstName' => '', 'lastName' => '', 'company' => 'Northwind Media'] + $data[$byCommission[4]]['record'];

    // Two eligible affiliates tied on commission (lower id ranks first).
    $data[$byCommission[7]]['flag'] = $data[$byCommission[8]]['flag'] = 'TRUE';
    $data[$byCommission[8]]['record']['performance'] = $data[$byCommission[7]]['record']['performance'];

    unset($data[$ids[10]]['record']['performance']);

    foreach ($data as $id => $entry) {
        $fields = [['id' => 501, 'label' => 'What is your website URL?', 'value' => "https://example.com/{$id}"]];
        if ($entry['flag'] !== null) {
            $fields[] = ['id' => 503, 'label' => 'Display in leaderboard', 'value' => $entry['flag']];
        }
        $data[$id] = ['record' => $entry['record'], 'fields' => $fields];
    }

    return $data;
}

function respond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}
