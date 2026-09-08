<?php

// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\Kubernetes;

class ObjectRepository
{
    protected static array $objects = [];

    public static function beginCalculation(): void
    {
        // The cache deduplicates batch, dependency and node reads only inside
        // one state calculation. A second calculation must observe a newer
        // API snapshot or an outage instead of inheriting the previous result.
        self::$objects = [];
    }

    public static function canAccessAny(): bool
    {
        return Feature::isAvailable();
    }

    public static function fetch(
        string $kind,
        string $uuid,
        ?string $cluster = null,
        ?string $group = null,
        ?string $version = null
    ): ?object
    {
        $key = self::cacheKey($kind, $uuid);
        if (array_key_exists($key, self::$objects)) {
            $object = self::$objects[$key];
            self::assertCluster($object, $cluster);
            self::assertGVK($object, $group, $version, $kind);
            return $object;
        }
        try {
            $items = (new ApiClient())->post('resources/batch-get', ['ids' => [$uuid]]);
        } catch (ApiNotFoundException $_) {
            return self::$objects[$key] = null;
        }
        foreach ($items as $item) {
            $object = self::object($item, null);
            if ($object->uuid !== $uuid) {
                throw new \RuntimeException('Kubernetes API returned an unexpected batch item');
            }
            if ($object->kind !== Kind::canonicalize($kind)) {
                throw new \RuntimeException('Kubernetes API returned a mismatched batch item');
            }
            self::assertCluster($object, $cluster);
            self::assertGVK($object, $group, $version, $kind);
            return self::$objects[$key] = $object;
        }
        if ($items === []) {
            return self::$objects[$key] = null;
        }

        throw new \RuntimeException('Kubernetes API returned an invalid batch response');
    }

    /** @param array<int, array{kind: string, uuid: string}> $references */
    public static function preload(array $references): void
    {
        $expected = [];
        foreach ($references as $reference) {
            $kind = Kind::canonicalize($reference['kind']);
            $uuid = $reference['uuid'];
            $key = self::cacheKey($kind, $uuid);
            if (! array_key_exists($key, self::$objects)) {
                $expected[$uuid] = [
                    'kind' => $kind,
                    'cluster' => $reference['cluster'] ?? null,
                    'group' => $reference['group'] ?? null,
                    'version' => $reference['version'] ?? null
                ];
            }
        }

        foreach (array_chunk(array_keys($expected), 1000) as $ids) {
            $items = (new ApiClient())->post('resources/batch-get', ['ids' => $ids]);
            $found = [];
            foreach ($items as $item) {
                if (! is_array($item) || ! is_string($item['id'] ?? null) || ! isset($expected[$item['id']])) {
                    throw new \RuntimeException('Kubernetes API returned an invalid batch item');
                }
                $object = self::object($item, null);
                $kind = $object->kind;
                if ($kind !== $expected[$item['id']]['kind']) {
                    throw new \RuntimeException('Kubernetes API returned a mismatched batch item');
                }
                self::assertCluster($object, $expected[$item['id']]['cluster']);
                self::assertGVK(
                    $object,
                    $expected[$item['id']]['group'],
                    $expected[$item['id']]['version'],
                    $expected[$item['id']]['kind']
                );
                $key = self::cacheKey($kind, $item['id']);
                self::$objects[$key] = $object;
                $found[$item['id']] = true;
            }
            foreach ($ids as $id) {
                if (! isset($found[$id])) {
                    self::$objects[self::cacheKey($expected[$id]['kind'], $id)] = null;
                }
            }
        }
    }

    public static function search(
        string $kind,
        string $term,
        int $limit = 50,
        ?string $cluster = null,
        ?string $group = null,
        ?string $version = null,
        ?string $cursor = null,
        ?string $namespace = null
    ): array
    {
        $term = trim($term, " *%\t\n\r\0\x0B");
        $page = (new ApiClient())->get('resources', [
            'cluster' => $cluster,
            'group' => $group,
            'version' => $version,
            'kind' => $kind,
            'namespace' => $namespace,
            'namePrefix' => $term,
            'limit' => min(100, max(1, $limit)),
            'cursor' => $cursor
        ]);
        self::assertPage($page);
        return [
            'items' => array_map(
                static fn(array $item): object => self::object($item, $page['freshness']),
                $page['items']
            ),
            'nextCursor' => $page['nextCursor'] ?? null,
            'freshness' => $page['freshness']
        ];
    }

    public static function resourceTypes(string $cluster): array
    {
        $page = (new ApiClient())->get('resource-types', ['cluster' => $cluster]);
        if (($page['cluster'] ?? null) !== $cluster
            || ! isset($page['items'], $page['freshness'])
            || ! is_array($page['items'])
            || ! array_is_list($page['items'])
            || count($page['items']) > 4096
        ) {
            throw new \RuntimeException('Kubernetes API returned invalid resource types');
        }
        self::freshness($page['freshness']);
        foreach ($page['items'] as $item) {
            if (! is_array($item)
                || ! is_string($item['group'] ?? null)
                || ! is_string($item['version'] ?? null)
                || $item['version'] === ''
                || ! is_string($item['kind'] ?? null)
                || $item['kind'] === ''
                || strlen($item['group']) > 512
                || strlen($item['version']) > 512
                || strlen($item['kind']) > 128
                || ! preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $item['kind'])
                || ! is_int($item['count'] ?? null)
                || $item['count'] < 1
            ) {
                throw new \RuntimeException('Kubernetes API returned an invalid resource type');
            }
        }
        return $page;
    }

    /** Namespaces of matching inventory; does not require Namespace objects. */
    public static function namespaces(string $cluster, array $type): array
    {
        $page = (new ApiClient())->get('resource-namespaces', [
            'cluster' => $cluster, 'group' => $type['group'],
            'version' => $type['version'], 'kind' => $type['kind']
        ]);
        if (! is_array($page['items'] ?? null) || ! array_is_list($page['items'])
            || count($page['items']) > 4096
        ) {
            throw new \RuntimeException('Invalid Kubernetes namespace inventory');
        }
        foreach ($page['items'] as $namespace) {
            if (! is_string($namespace) || strlen($namespace) > 63
                || ! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $namespace)
            ) {
                throw new \RuntimeException('Invalid Kubernetes namespace');
            }
        }
        return $page['items'];
    }

    /**
     * Return the local cluster and all configured direct federation branches.
     *
     * The branch endpoint intentionally returns no URLs or credentials. Its
     * result is therefore safe to use as the bounded source for an editor
     * choice and cannot accidentally introduce transitive federation.
     *
     * @return array<string, string> cluster name => local/direct source
     */
    public static function clusters(): array
    {
        $client = new ApiClient();
        $status = $client->get('status');
        if (! self::validClusterName($status['cluster'] ?? null)) {
            throw new \RuntimeException('Kubernetes API returned an invalid local cluster');
        }
        self::freshness($status['freshness'] ?? null);
        $clusters = [$status['cluster'] => 'local'];

        $branches = $client->get('branches');
        if (! isset($branches['items'], $branches['transitive'])
            || ! is_array($branches['items'])
            || ! array_is_list($branches['items'])
            || $branches['transitive'] !== false
            || count($branches['items']) > 64
        ) {
            throw new \RuntimeException('Kubernetes API returned invalid federation branches');
        }
        foreach ($branches['items'] as $branch) {
            if (! is_array($branch)
                || ! is_string($branch['name'] ?? null)
                || ! self::validClusterName($branch['name'])
                || isset($clusters[$branch['name']])
            ) {
                throw new \RuntimeException('Kubernetes API returned an invalid federation branch');
            }
            $clusters[$branch['name']] = 'direct';
        }

        return $clusters;
    }

    public static function childrenMany(array $parents): array
    {
        if ($parents === []) {
            return [];
        }
        $ids = array_values(array_unique(array_column($parents, 'uuid')));
        $result = [];
        $keysById = [];
        foreach ($parents as $parent) {
            $kind = Kind::canonicalize($parent['kind']);
            $id = $parent['uuid'];
            $key = self::cacheKey($kind, $id);
            $result[$key] = [];
            $keysById[$id] = $key;
        }
        foreach (array_chunk($ids, 100) as $chunk) {
            $response = (new ApiClient())->post('graph/resolve', ['ids' => $chunk, 'depth' => 1]);
            if (! isset($response['children'], $response['depth'], $response['snapshot'], $response['freshness'])
                || ! is_array($response['children'])
                || $response['depth'] !== 1
                || ! is_string($response['snapshot'])
                || ! is_string($response['freshness'])
                || ! in_array($response['freshness'], ['live', 'stale', 'unavailable'], true)
            ) {
                throw new \RuntimeException('Kubernetes API returned an invalid dependency graph');
            }
            if ($response['freshness'] !== 'live') {
                throw new \RuntimeException('Kubernetes dependency graph is not current');
            }
            foreach ($chunk as $id) {
                $key = $keysById[$id];
                $children = $response['children'][$id] ?? [];
                if (! is_array($children) || ! array_is_list($children)) {
                    throw new \RuntimeException('Kubernetes API returned invalid graph children');
                }
                foreach ($children as $child) {
                    $object = self::object($child, $response['freshness']);
                    self::$objects[self::cacheKey($object->kind, $object->uuid)] = $object;
                    $result[$key][] = ['kind' => $object->kind, 'uuid' => $object->uuid];
                }
            }
        }
        return $result;
    }

    public static function labelParts(string $kind, object $object): array
    {
        $gvk = ($object->group === '' ? 'core' : $object->group)
            . '/' . $object->version . ', ' . $object->api_kind;
        $freshness = ($object->freshness ?? 'live') === 'live'
            ? null
            : '[' . $object->freshness . ']';

        return array_values(array_filter([
            $object->cluster_name ?? null,
            $freshness,
            $gvk,
            $object->namespace ?? null,
            $object->name ?? null
        ], static fn($part) => $part !== null && $part !== ''));
    }

    public static function clusterUuidFor(string $kind, object $object): ?string
    {
        return $object->cluster_name ?? null;
    }

    public static function clusterName(string $clusterUuid): string
    {
        return $clusterUuid;
    }

    public static function namespaceFor(string $kind, object $object): ?string
    {
        return $object->namespace ?? null;
    }

    public static function uuidToString($uuid): string
    {
        return (string) $uuid;
    }

    private static function object(array $item, ?string $fallbackFreshness): object
    {
        foreach (['id', 'uid', 'cluster', 'group', 'version', 'kind', 'name', 'resourceVersion', 'state', 'observedAt'] as $field) {
            if (! array_key_exists($field, $item)
                || ! is_string($item[$field])
                || ($field !== 'group' && $item[$field] === '')
            ) {
                throw new \RuntimeException('Kubernetes API returned an incomplete resource');
            }
        }
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $item['id'])
            || ! in_array($item['state'], ['ok', 'warning', 'critical', 'unknown'], true)
            || (isset($item['namespace']) && ! is_string($item['namespace']))
            || (isset($item['labels']) && ! is_array($item['labels']))
            || (isset($item['conditions']) && ! is_array($item['conditions']))
        ) {
            throw new \RuntimeException('Kubernetes API returned an invalid resource');
        }
        $freshness = $item['freshness'] ?? $fallbackFreshness;
        if (! is_string($freshness) || ! in_array($freshness, ['live', 'stale', 'unavailable'], true)) {
            throw new \RuntimeException('Kubernetes API returned invalid resource freshness');
        }

        return (object) [
            'uuid' => $item['id'],
            'uid' => $item['uid'],
            'kind' => Kind::canonicalize($item['kind']),
            'api_kind' => $item['kind'],
            'group' => $item['group'],
            'version' => $item['version'],
            'cluster_uuid' => $item['cluster'],
            'cluster_name' => $item['cluster'],
            'namespace' => (string) ($item['namespace'] ?? ''),
            'name' => $item['name'],
            'icinga_state' => $item['state'],
            'icinga_state_reason' => (string) ($item['reason'] ?? ''),
            'labels' => $item['labels'] ?? [],
            'conditions' => $item['conditions'] ?? [],
            'freshness' => $freshness
        ];
    }

    private static function assertPage(array $page): void
    {
        if (! isset($page['items'], $page['snapshot'], $page['freshness'])
            || ! is_array($page['items'])
            || ! array_is_list($page['items'])
            || ! is_string($page['snapshot'])
            || $page['snapshot'] === ''
            || ! is_string($page['freshness'])
            || ! in_array($page['freshness'], ['live', 'stale', 'unavailable'], true)
            || (isset($page['nextCursor'])
                && (! is_string($page['nextCursor'])
                    || strlen($page['nextCursor']) > 4096
                    || ! preg_match('/^[A-Za-z0-9_-]+$/D', $page['nextCursor'])))
        ) {
            throw new \RuntimeException('Kubernetes API returned an invalid resource page');
        }
    }

    private static function freshness($freshness): string
    {
        if (! is_string($freshness) || ! in_array($freshness, ['live', 'stale', 'unavailable'], true)) {
            throw new \RuntimeException('Kubernetes API returned invalid freshness');
        }

        return $freshness;
    }

    private static function validClusterName($name): bool
    {
        return is_string($name)
            && $name !== ''
            && strlen($name) <= 253
            && preg_match('/^[A-Za-z0-9_.-]+$/D', $name) === 1;
    }

    private static function assertCluster(?object $object, ?string $cluster): void
    {
        if ($object !== null && $cluster !== null && $cluster !== '' && $object->cluster_name !== $cluster) {
            throw new \RuntimeException('Kubernetes API returned an object from an unexpected cluster');
        }
    }

    private static function assertGVK(?object $object, ?string $group, ?string $version, string $kind): void
    {
        if ($object === null) {
            return;
        }
        if (($group !== null && $object->group !== $group)
            || ($version !== null && $object->version !== $version)
            || strcasecmp($object->api_kind, $kind) !== 0
        ) {
            throw new \RuntimeException('Kubernetes API returned an object with an unexpected GVK');
        }
    }

    private static function cacheKey(string $kind, string $uuid): string
    {
        return Kind::canonicalize($kind) . ':' . $uuid;
    }
}
