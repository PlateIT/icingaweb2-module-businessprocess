<?php

// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\Storage;

use Icinga\Module\Businessprocess\BpConfig;
use Icinga\Module\Businessprocess\BpNode;
use Icinga\Module\Businessprocess\ImportedNode;
use Icinga\Module\Businessprocess\KubernetesNode;
use Icinga\Module\Businessprocess\KubernetesSelectorNode;
use Icinga\Module\Businessprocess\PublicHealth\PublicHealthService;
use RuntimeException;

final class DefinitionCodec
{
    private const VERSION = 2;
    private const MAX_NODES = 10000;

    public static function encode(BpConfig $config): array
    {
        if (! $config->getMetadata()->get('PublicApiPath')) {
            $config->getMetadata()->set('PublicApiPath', PublicHealthService::slug($config->getName()));
        }
        PublicHealthService::validateConfiguration($config);
        $nodes = [];
        foreach ($config->getBpNodes() as $node) {
            if ($node instanceof KubernetesNode && ! $node->isExplicit()) {
                continue;
            }
            $nodes[] = self::encodeNode($node);
        }

        return [
            'version' => self::VERSION,
            'metadata' => array_filter(
                $config->getMetadata()->getProperties(),
                static fn($value): bool => $value !== null
            ),
            'nodes' => $nodes,
            'roots' => array_values($config->listRootNodes())
        ];
    }

    public static function decode(string $name, array $definition): BpConfig
    {
        $fields = array_keys($definition);
        sort($fields);
        if ($fields !== ['metadata', 'nodes', 'roots', 'version']) {
            throw new RuntimeException('Business process definition contains unknown or missing fields');
        }
        if (($definition['version'] ?? null) !== self::VERSION) {
            throw new RuntimeException('Unsupported business process definition version');
        }
        if (! is_array($definition['metadata'] ?? null)
            || ! is_array($definition['nodes'] ?? null)
            || ! is_array($definition['roots'] ?? null)
            || ! array_is_list($definition['nodes'])
            || ! array_is_list($definition['roots'])
            || count($definition['nodes']) > self::MAX_NODES
            || count($definition['roots']) > self::MAX_NODES
        ) {
            throw new RuntimeException('Invalid business process definition');
        }

        $config = new BpConfig($name);
        foreach ($definition['metadata'] as $key => $value) {
            if (! is_string($key)
                || strlen($key) > 128
                || ! is_string($value)
                || strlen($value) > 4096
                || ! $config->getMetadata()->hasKey($key)
            ) {
                throw new RuntimeException('Invalid business process metadata');
            }
            $config->getMetadata()->set($key, $value);
        }

        $seen = [];
        foreach ($definition['nodes'] as $data) {
            $nodeName = is_array($data) ? ($data['name'] ?? null) : null;
            if (! is_string($nodeName) || $nodeName === '' || strlen($nodeName) > 512 || isset($seen[$nodeName])) {
                throw new RuntimeException('Duplicate or empty business process node');
            }
            $seen[$nodeName] = true;
            self::validateNodeShape($data);
            self::decodeNode($config, $data);
        }
        $seenRoots = [];
        foreach ($definition['roots'] as $root) {
            if (! is_string($root) || ! $config->hasBpNode($root) || isset($seenRoots[$root])) {
                throw new RuntimeException('Invalid business process root');
            }
            $seenRoots[$root] = true;
            $config->addRootNode($root);
        }

        // Preserve the URL of definitions created before stored public paths.
        // Subsequent edits and exports persist it independently of the title.
        if (! $config->getMetadata()->get('PublicApiPath')) {
            $config->getMetadata()->set('PublicApiPath', PublicHealthService::slug($config->getTitle()));
        }
        PublicHealthService::validateConfiguration($config);
        return $config;
    }

    private static function encodeNode(BpNode $node): array
    {
        if ($node instanceof ImportedNode) {
            return [
                'type' => 'imported',
                'name' => $node->getName(),
                'config' => $node->getConfigName(),
                'node' => $node->getNodeName()
            ];
        }

        $data = [
            'type' => 'process',
            'name' => $node->getName(),
            'operator' => $node->getOperator(),
            'children' => array_values($node->getChildNames()),
            'display' => $node->getDisplay(),
            'alias' => $node->hasAlias()
                ? (method_exists($node, 'getStoredAlias') ? $node->getStoredAlias() : $node->getAlias())
                : null,
            'infoUrl' => ! ($node instanceof KubernetesNode) && $node->hasInfoUrl() ? $node->getInfoUrl() : null,
            'stateOverrides' => $node->getStateOverrides(),
            'publicStatus' => get_class($node) === BpNode::class && $node->getPublicStatus()
        ];

        if ($node instanceof KubernetesSelectorNode) {
            $data['type'] = 'kubernetes-selector';
            $data['children'] = [];
            $data['selector'] = $node->getSelector();
            $data['aggregation'] = $node->getAggregation();
        } elseif ($node instanceof KubernetesNode) {
            $data['type'] = 'kubernetes';
            $data['children'] = [];
            unset($data['infoUrl']);
            $data['kind'] = $node->getKind();
            $data['uuid'] = $node->getUuid();
            $data['cluster'] = $node->getExpectedCluster();
            $data['group'] = $node->getExpectedGroup();
            $data['version'] = $node->getExpectedVersion();
            $data['apiKind'] = $node->getApiKind();
            $data['expandDependencies'] = $node->getExpandDependencies();
            $data['namespaceInclude'] = $node->getNamespaceInclude();
        }

        return array_filter($data, static fn($value): bool => $value !== null);
    }

    private static function decodeNode(BpConfig $config, $data): void
    {
        if (! is_array($data) || ! is_string($data['type'] ?? null) || ! is_string($data['name'] ?? null)) {
            throw new RuntimeException('Invalid business process node');
        }
        $name = $data['name'];
        switch ($data['type']) {
            case 'process':
                $node = $config->createBp($name, $data['operator'] ?? BpNode::OP_AND);
                break;
            case 'kubernetes-selector':
                $node = $config->createKubernetesSelectorNode($name);
                $node->setSelector(self::arrayField($data, 'selector'));
                $node->setAggregation(self::stringField($data, 'aggregation', 'worst'));
                break;
            case 'kubernetes':
                $node = $config->createKubernetesNode(
                    self::requiredString($data, 'kind', 128),
                    self::requiredString($data, 'uuid')
                );
                $node->setExpectedCluster(self::requiredString($data, 'cluster', 253));
                $node->setExpectedGroup(self::stringField($data, 'group', ''));
                $node->setExpectedVersion(self::requiredString($data, 'version', 512));
                $node->setApiKind(self::requiredString($data, 'apiKind', 128));
                if ($node->getName() !== $name) {
                    throw new RuntimeException('Kubernetes node identity does not match its name');
                }
                $node->setExpandDependencies((bool) ($data['expandDependencies'] ?? true));
                $node->setNamespaceInclude(self::arrayField($data, 'namespaceInclude'));
                break;
            case 'imported':
                $node = $config->createImportedNode(
                    self::requiredString($data, 'config', 512),
                    self::requiredString($data, 'node', 512)
                );
                if ($node->getName() !== $name) {
                    throw new RuntimeException('Imported node identity does not match its name');
                }
                break;
            default:
                throw new RuntimeException('Unsupported business process node type');
        }

        if (isset($data['children'])) {
            if (! is_array($data['children'])
                || ! array_is_list($data['children'])
                || count($data['children']) > self::MAX_NODES
                || count(array_unique($data['children'], SORT_REGULAR)) !== count($data['children'])
            ) {
                throw new RuntimeException('Invalid business process children');
            }
            foreach ($data['children'] as $child) {
                if (! is_string($child) || $child === '' || strlen($child) > 512) {
                    throw new RuntimeException('Invalid business process child');
                }
            }
            $node->setChildNames($data['children']);
        }
        if (isset($data['alias'])) {
            $node->setAlias(self::requiredString($data, 'alias'));
        }
        $node->setDisplay((int) ($data['display'] ?? 0));
        if (isset($data['infoUrl']) && $data['type'] === 'process') {
            $node->setInfoUrl(self::requiredString($data, 'infoUrl'));
        }
        if (isset($data['stateOverrides'])) {
            if (! is_array($data['stateOverrides'])) {
                throw new RuntimeException('Invalid business process state overrides');
            }
            $node->setStateOverrides($data['stateOverrides']);
        }
        if ($data['type'] === 'process') {
            $node->setPublicStatus((bool) ($data['publicStatus'] ?? false));
        }
    }

    private static function validateNodeShape(array $data): void
    {
        $common = [
            'type', 'name', 'operator', 'children', 'display', 'alias',
            'infoUrl', 'stateOverrides', 'publicStatus'
        ];
        $allowed = match ($data['type'] ?? null) {
            'process' => $common,
            'kubernetes-selector' => array_merge($common, ['selector', 'aggregation']),
            'kubernetes' => array_merge($common, [
                'kind', 'uuid', 'cluster', 'group', 'version', 'apiKind',
                'expandDependencies', 'namespaceInclude'
            ]),
            'imported' => ['type', 'name', 'config', 'node'],
            default => throw new RuntimeException('Unsupported business process node type')
        };
        if (array_diff(array_keys($data), $allowed) !== []) {
            throw new RuntimeException('Business process node contains unknown fields');
        }
        if (isset($data['display']) && ! is_int($data['display'])) {
            throw new RuntimeException('Invalid business process display value');
        }
        if (isset($data['operator']) && ! self::validOperator($data['operator'])) {
            throw new RuntimeException('Invalid business process operator');
        }
        foreach (['publicStatus', 'expandDependencies'] as $field) {
            if (isset($data[$field]) && ! is_bool($data[$field])) {
                throw new RuntimeException("Invalid $field");
            }
        }
        if (($data['type'] ?? null) === 'kubernetes-selector') {
            if (! array_key_exists('selector', $data)) {
                throw new RuntimeException('Missing Kubernetes selector');
            }
            $selector = self::arrayField($data, 'selector');
            $selectorFields = ['cluster', 'group', 'version', 'kind', 'namespace', 'name', 'labels', 'ownerUID', 'states'];
            if (array_diff(array_keys($selector), $selectorFields) !== []) {
                throw new RuntimeException('Kubernetes selector contains unknown fields');
            }
            foreach ($selector as $field => $value) {
                if ($field === 'states') {
                    if (! is_array($value)
                        || ! array_is_list($value)
                        || array_filter($value, 'is_string') !== $value
                        || array_diff($value, ['ok', 'warning', 'critical', 'unknown']) !== []
                        || count(array_unique($value)) !== count($value)
                    ) {
                        throw new RuntimeException('Invalid Kubernetes selector states');
                    }
                } elseif (! is_string($value)
                    || strlen($value) > ($field === 'labels' ? 4096 : 512)
                    || str_contains($value, "\0")
                ) {
                    throw new RuntimeException("Invalid Kubernetes selector $field");
                }
            }
            if (! in_array($data['aggregation'] ?? 'worst', ['and', 'or', 'worst'], true)) {
                throw new RuntimeException('Invalid Kubernetes selector aggregation');
            }
        }
        if (($data['type'] ?? null) === 'kubernetes' && isset($data['namespaceInclude'])) {
            if (! is_array($data['namespaceInclude'])
                || ! array_is_list($data['namespaceInclude'])
                || array_filter($data['namespaceInclude'], 'is_string') !== $data['namespaceInclude']
                || count(array_unique($data['namespaceInclude'])) !== count($data['namespaceInclude'])
                || array_diff($data['namespaceInclude'], \Icinga\Module\Businessprocess\Kubernetes\Kind::NAMESPACE_INCLUDE_OPTIONS) !== []
            ) {
                throw new RuntimeException('Invalid Kubernetes namespace include list');
            }
        }
        if (($data['type'] ?? null) === 'kubernetes') {
            $cluster = $data['cluster'] ?? null;
            if (! is_string($cluster)
                || $cluster === ''
                || strlen($cluster) > 253
                || ! preg_match('/^[A-Za-z0-9_.-]+$/D', $cluster)
            ) {
                throw new RuntimeException('Invalid Kubernetes node cluster');
            }
            foreach (['group', 'version', 'apiKind'] as $field) {
                $value = $data[$field] ?? null;
                if (! is_string($value)
                    || ($field !== 'group' && $value === '')
                    || strlen($value) > ($field === 'apiKind' ? 128 : 512)
                    || str_contains($value, "\0")
                ) {
                    throw new RuntimeException("Invalid Kubernetes node $field");
                }
            }
            $uuid = $data['uuid'] ?? null;
            if (! is_string($uuid)
                || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $uuid)
            ) {
                throw new RuntimeException('Invalid Kubernetes node UUID');
            }
        }
    }

    private static function requiredString(array $data, string $key, int $maximum = 4096): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value) || $value === '' || strlen($value) > $maximum || str_contains($value, "\0")) {
            throw new RuntimeException("Missing or invalid $key");
        }
        return $value;
    }

    private static function stringField(array $data, string $key, string $default): string
    {
        if (! array_key_exists($key, $data)) {
            return $default;
        }
        if (! is_string($data[$key])) {
            throw new RuntimeException("Invalid $key");
        }
        return $data[$key];
    }

    private static function arrayField(array $data, string $key): array
    {
        if (! array_key_exists($key, $data)) {
            return [];
        }
        if (! is_array($data[$key])) {
            throw new RuntimeException("Invalid $key");
        }
        return $data[$key];
    }

    /** @param mixed $operator */
    private static function validOperator($operator): bool
    {
        if (is_string($operator) && in_array($operator, ['&', '|', '^', '!', '%'], true)) {
            return true;
        }
        if (is_int($operator)) {
            return $operator >= 1 && $operator <= self::MAX_NODES;
        }

        return is_string($operator)
            && ctype_digit($operator)
            && (int) $operator >= 1
            && (int) $operator <= self::MAX_NODES;
    }
}
