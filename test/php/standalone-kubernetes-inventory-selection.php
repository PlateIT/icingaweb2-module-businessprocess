<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Icinga\Application {
    class Config
    {
        public static function module(string $name): \InventoryTestConfig
        {
            return new \InventoryTestConfig();
        }
    }
}

namespace {
    final class InventoryTestSection
    {
        public function get(string $key, mixed $default = null): mixed
        {
            return match ($key) {
                'api_url' => getenv('ICINGA_KUBERNETES_API_URL'),
                'api_timeout' => 5,
                'api_token_file' => getenv('ICINGA_KUBERNETES_API_ADMIN_TOKEN_FILE'),
                default => $default
            };
        }
    }

    final class InventoryTestConfig
    {
        public function getSection(string $name): InventoryTestSection
        {
            return new InventoryTestSection();
        }
    }

    $root = dirname(__DIR__, 2);
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'Icinga\\Module\\Businessprocess\\';
        if (str_starts_with($class, $prefix)) {
            $path = $root . '/library/Businessprocess/'
                . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require $path;
            }
        }
    });

    use Icinga\Module\Businessprocess\Kubernetes\ObjectRepository;

    $queryFile = $argv[1] ?? throw new RuntimeException('query-file argument is required');
    $clusters = ObjectRepository::clusters();
    if ($clusters !== ['primus' => 'local', 'campus' => 'direct', 'remote-down' => 'direct']) {
        throw new RuntimeException('Unexpected bounded direct-cluster inventory: ' . json_encode($clusters));
    }

    $types = ObjectRepository::resourceTypes('campus');
    if (($types['items'][1] ?? null) !== [
        'group' => 'example.io', 'version' => 'v1alpha1', 'kind' => 'Database', 'count' => 7
    ]) {
        throw new RuntimeException('Operator-provided CRD type was not discoverable');
    }

    $page = ObjectRepository::search('Deployment', 'check', 50, 'campus', 'apps', 'v1');
    $objects = $page['items'];
    if (count($objects) !== 1 || $objects[0]->cluster_name !== 'campus') {
        throw new RuntimeException('External cluster search returned the wrong object');
    }
    $label = implode(' / ', ObjectRepository::labelParts('deployment', $objects[0]));
    if ($label !== 'campus / apps/v1, Deployment / payments / checkout-v2') {
        throw new RuntimeException('Selection label does not expose cluster and GVK: ' . $label);
    }
    $query = json_decode((string) file_get_contents($queryFile), true, 512, JSON_THROW_ON_ERROR);
    if (($query['cluster'] ?? null) !== 'campus'
        || ($query['kind'] ?? null) !== 'Deployment'
        || ($query['group'] ?? null) !== 'apps'
        || ($query['version'] ?? null) !== 'v1'
        || ($query['namePrefix'] ?? null) !== 'check'
        || ($query['limit'] ?? null) !== '50'
    ) {
        throw new RuntimeException('Selection request is not bounded and cluster-scoped: ' . json_encode($query));
    }
    $unavailable = ObjectRepository::search('Deployment', 'check', 50, 'remote-down', 'apps', 'v1');
    $unavailableLabel = implode(
        ' / ',
        ObjectRepository::labelParts('deployment', $unavailable['items'][0])
    );
    if ($unavailable['freshness'] !== 'unavailable'
        || $unavailableLabel !== 'remote-down / [unavailable] / apps/v1, Deployment / payments / checkout-v2'
    ) {
        throw new RuntimeException('Unavailable external inventory was not made visible: ' . $unavailableLabel);
    }

    try {
        ObjectRepository::beginCalculation();
        ObjectRepository::preload([[
            'kind' => 'deployment',
            'uuid' => $objects[0]->uuid,
            'cluster' => 'primus'
        ]]);
        throw new RuntimeException('Cross-cluster object identity mismatch was accepted');
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'Kubernetes API returned an object from an unexpected cluster') {
            throw $e;
        }
    }
    try {
        ObjectRepository::beginCalculation();
        ObjectRepository::preload([[
            'kind' => 'deployment',
            'uuid' => $objects[0]->uuid,
            'cluster' => 'campus',
            'group' => 'wrong.example',
            'version' => 'v1'
        ]]);
        throw new RuntimeException('Cross-GVK object identity mismatch was accepted');
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'Kubernetes API returned an object with an unexpected GVK') {
            throw $e;
        }
    }

    echo "Kubernetes inventory selection/federation test passed.\n";
}
