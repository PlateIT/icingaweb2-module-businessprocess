<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$query = [];
parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
header('Content-Type: application/json');

if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer inventory-test-token') {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    return;
}

if ($path === '/api/v1/status') {
    echo json_encode(['cluster' => 'primus', 'database' => 'ready', 'freshness' => 'live']);
    return;
}
if ($path === '/api/v1/branches') {
    echo json_encode([
        'transitive' => false,
        'items' => [
            ['name' => 'campus'],
            ['name' => 'remote-down']
        ]
    ]);
    return;
}
if ($path === '/api/v1/resource-types') {
    echo json_encode([
        'cluster' => (string) ($query['cluster'] ?? 'primus'),
        'freshness' => 'live',
        'items' => [
            ['group' => 'apps', 'version' => 'v1', 'kind' => 'Deployment', 'count' => 123],
            ['group' => 'example.io', 'version' => 'v1alpha1', 'kind' => 'Database', 'count' => 7]
        ]
    ]);
    return;
}
if ($path === '/api/v1/resources') {
    file_put_contents((string) getenv('BP_INVENTORY_QUERY_FILE'), json_encode($query, JSON_THROW_ON_ERROR));
    $freshness = ($query['cluster'] ?? '') === 'remote-down' ? 'unavailable' : 'live';
    echo json_encode([
        'snapshot' => '42',
        'freshness' => $freshness,
        'items' => [[
            'id' => '10000000-0000-4000-8000-000000000000',
            'uid' => 'uid-1',
            'cluster' => (string) ($query['cluster'] ?? 'primus'),
            'group' => 'apps',
            'version' => 'v1',
            'kind' => 'Deployment',
            'namespace' => 'payments',
            'name' => 'checkout-v2',
            'resourceVersion' => '7',
            'state' => 'ok',
            'observedAt' => '2026-09-01T00:00:00Z',
            'freshness' => $freshness
        ]]
    ]);
    return;
}
if ($path === '/api/v1/resource-namespaces') {
    if (($query['cluster'] ?? '') !== 'campus' || ($query['kind'] ?? '') !== 'Deployment'
        || ($query['group'] ?? '') !== 'apps' || ($query['version'] ?? '') !== 'v1') {
        http_response_code(400);
        echo json_encode(['error' => 'namespace inventory must be scoped']);
        return;
    }
    echo json_encode(['items' => ['payments'], 'cluster' => 'campus', 'freshness' => 'live']);
    return;
}
if ($path === '/api/v1/resources/batch-get') {
    echo json_encode([[
        'id' => '10000000-0000-4000-8000-000000000000',
        'uid' => 'uid-1',
        'cluster' => 'campus',
        'group' => 'apps',
        'version' => 'v1',
        'kind' => 'Deployment',
        'namespace' => 'payments',
        'name' => 'checkout-v2',
        'resourceVersion' => '7',
        'state' => 'ok',
        'observedAt' => '2026-09-01T00:00:00Z',
        'freshness' => 'live'
    ]]);
    return;
}

http_response_code(404);
echo json_encode(['error' => 'not found']);
