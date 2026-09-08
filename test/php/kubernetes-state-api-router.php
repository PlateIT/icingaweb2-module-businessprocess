<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

$stateFile = getenv('BP_KUBERNETES_STATE_FILE');
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer state-test-token') {
    http_response_code(401);
    echo '{"error":"unauthorized"}';
    return;
}
$state = json_decode((string) file_get_contents($stateFile), true, 8, JSON_THROW_ON_ERROR);
if ($state['fail'] ?? false) {
    http_response_code(503);
    echo '{"error":"simulated source outage"}';
    return;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $_SERVER['REQUEST_URI'] !== '/api/v1/resources/batch-get') {
    http_response_code(404);
    echo '{"error":"not found"}';
    return;
}
$request = json_decode((string) file_get_contents('php://input'), true, 8, JSON_THROW_ON_ERROR);
$id = '10000000-0000-4000-8000-000000000000';
$items = in_array($id, $request['ids'] ?? [], true) ? [[
    'id' => $id,
    'uid' => 'source-pod',
    'cluster' => 'test-cluster',
    'group' => '',
    'version' => 'v1',
    'kind' => 'Pod',
    'namespace' => 'default',
    'name' => 'state-test',
    'resourceVersion' => (string) ($state['version'] ?? 1),
    'state' => (string) ($state['state'] ?? 'unknown'),
    'observedAt' => '2026-09-01T08:00:00Z',
    'freshness' => (string) ($state['freshness'] ?? 'live')
]] : [];
header('Content-Type: application/json');
echo json_encode($items, JSON_THROW_ON_ERROR);
