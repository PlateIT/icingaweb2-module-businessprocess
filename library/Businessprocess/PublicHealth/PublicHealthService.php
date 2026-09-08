<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\PublicHealth;

use DateTimeImmutable;
use DateTimeZone;
use Icinga\Module\Businessprocess\BpConfig;
use Icinga\Module\Businessprocess\BpNode;
use Icinga\Module\Businessprocess\Metadata;
use Icinga\Module\Businessprocess\Storage\Storage;
use RuntimeException;
use Throwable;

class PublicHealthService
{
    private const MAX_PUBLIC_CONFIGS = 100;
    private const MAX_PUBLIC_NODES_PER_CONFIG = 1000;
    protected Storage $storage;

    protected $stateLoader;

    public function __construct(Storage $storage, ?callable $stateLoader = null)
    {
        $this->storage = $storage;
        $this->stateLoader = $stateLoader ?? function (BpConfig $config): void {
            $config->applyDbStates();
        };
    }

    public static function slug(string $name): string
    {
        $name = strtr($name, [
            'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'
        ]);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $slug = strtolower($ascii === false ? $name : $ascii);
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $slug), '-');

        if ($slug === '') {
            return 'service-' . substr(hash('sha256', $name), 0, 8);
        }
        if (strlen($slug) > 63) {
            $slug = rtrim(substr($slug, 0, 54), '-') . '-' . substr(hash('sha256', $name), 0, 8);
        }

        return $slug;
    }

    public static function pathForConfig(BpConfig $config): string
    {
        return self::pathForMetadata($config->getMetadata());
    }

    public static function pathForMetadata(Metadata $metadata): string
    {
        return $metadata->get('PublicApiPath') ?: self::slug($metadata->getTitle());
    }

    public static function isValidConfigPath(string $path): bool
    {
        return strlen($path) <= 63 && self::isPublicPath($path, false);
    }

    public static function validateConfiguration(BpConfig $config): void
    {
        $metadata = $config->getMetadata();
        if (! in_array($metadata->get('PublicApi', 'no'), ['yes', 'no'], true)
            || ! in_array($metadata->getPublicApiScope(), ['roots', 'published'], true)
            || ! in_array($metadata->getPublicApiRelations(), ['none', 'links'], true)
            || ! self::isValidConfigPath(self::pathForConfig($config))
        ) {
            throw new RuntimeException('Invalid public health configuration');
        }
        if ($metadata->isPublicApiEnabled()) {
            self::assertValidNodePaths($config, $metadata->getPublicApiScope());
        }
    }

    public static function pathForNode(BpConfig $config, BpNode $node): string
    {
        $paths = [];
        foreach ($node->getPaths($config) as $path) {
            $segments = [];
            foreach ($path as $name) {
                if ($name === '__unbound__' || ! $config->hasNode($name)) {
                    continue 2;
                }
                $pathNode = $config->getBpNode($name);
                if (! ($pathNode instanceof BpNode)) {
                    continue 2;
                }
                if (! $pathNode->getPublicStatus()) {
                    continue;
                }
                $segments[] = self::slug($pathNode->getAlias() ?: $pathNode->getName());
            }
            if ($segments !== []) {
                $paths[] = implode('/', $segments);
            }
        }
        if ($paths === []) {
            return self::slug($node->getAlias() ?: $node->getName());
        }

        usort($paths, static function (string $left, string $right): int {
            return substr_count($left, '/') <=> substr_count($right, '/') ?: strcmp($left, $right);
        });
        return $paths[0];
    }

    public static function assertValidNodePaths(BpConfig $config, string $scope): void
    {
        if (! in_array($scope, ['roots', 'published'], true)) {
            throw new RuntimeException('Invalid public node scope');
        }

        if ($scope === 'roots') {
            foreach ($config->getBpNodes() as $node) {
                if (
                    get_class($node) === BpNode::class
                    && $node->getPublicStatus()
                    && ! $config->hasRootNode($node->getName())
                ) {
                    throw new RuntimeException('Published node is outside the configured root scope');
                }
            }
        }

        self::collectPublishedNodes($config, $scope);
    }

    public function catalog(): array
    {
        $observedAt = $this->observedAt();
        $components = [];
        foreach ($this->publishedConfigs() as $configPath => $config) {
            $components[$configPath] = $this->configDto($configPath, $config, $observedAt);
        }

        return [
            'status' => StatusMapper::aggregate(array_column($components, 'status')),
            'components' => $components
        ];
    }

    public function config(string $configPath): ?array
    {
        if (! self::isPublicPath($configPath, false)) {
            return null;
        }
        $config = $this->publishedConfigs()[$configPath] ?? null;
        if ($config === null) {
            return null;
        }

        return $this->configDto($configPath, $config, $this->observedAt());
    }

    public function node(string $path): ?array
    {
        if (! self::isPublicPath($path, true)) {
            return null;
        }
        [$configPath, $nodePath] = array_pad(explode('/', trim($path, '/'), 2), 2, null);
        $config = $this->publishedConfigs()[$configPath] ?? null;
        if ($config === null) {
            return null;
        }

        $observedAt = $this->observedAt();
        $nodes = $this->publishedNodes($config);
        if ($nodePath === null || ! isset($nodes[$nodePath])) {
            return null;
        }

        $this->applyStates($config);

        return $this->nodeDto($configPath, $config, $nodes[$nodePath], $nodes, $observedAt);
    }

    public function discovery(): array
    {
        // Verify the authoritative API/index before reporting the health
        // provider itself as available. Backend errors are sanitized by the
        // dedicated controller and become UNKNOWN/503.
        $this->storage->listAllProcessNames();
        return [
            'status' => StatusMapper::UP,
            'components' => [
                'businessprocess' => [
                    'status' => StatusMapper::UP,
                    'details' => ['href' => '/businessprocess/health']
                ]
            ]
        ];
    }

    protected function configDto(string $configPath, BpConfig $config, string $observedAt): array
    {
        $nodes = $this->publishedNodes($config);
        $this->applyStates($config);

        $components = [];
        foreach ($nodes as $nodePath => $node) {
            $components[$nodePath] = $this->nodeDto(
                $configPath,
                $config,
                $node,
                $nodes,
                $observedAt
            );
        }

        return [
            'status' => StatusMapper::aggregate(array_column($components, 'status')),
            'components' => $components,
            'details' => [
                'name' => $config->getTitle(),
                'path' => $configPath,
                'observedAt' => $observedAt,
                'href' => $this->configUrl($configPath)
            ]
        ];
    }

    /** @return array<string, BpConfig> */
    protected function publishedConfigs(): array
    {
        $configs = [];
        foreach ($this->storage->listAllProcessNames() as $name) {
            $metadata = $this->storage->loadMetadata($name);
            if (! $this->isPublishedMetadata($metadata)) {
                continue;
            }

            $config = $this->storage->loadProcess($name);
            self::validateConfiguration($config);
            $configPath = self::pathForConfig($config);
            if (isset($configs[$configPath])) {
                throw new RuntimeException('Duplicate public configuration path');
            }

            $configs[$configPath] = $config;
            if (count($configs) > self::MAX_PUBLIC_CONFIGS) {
                throw new RuntimeException('Too many public Business Process configurations');
            }
        }

        return $configs;
    }

    protected static function isPublicPath(string $path, bool $allowHierarchy): bool
    {
        if ($path === '' || strlen($path) > 2048) {
            return false;
        }

        $segment = '[a-z0-9]+(?:-[a-z0-9]+)*';
        return preg_match($allowHierarchy ? "~^{$segment}(?:/{$segment})+$~D" : "~^{$segment}$~D", $path) === 1;
    }

    protected function isPublishedMetadata(Metadata $metadata): bool
    {
        return $metadata->isPublicApiEnabled()
            && in_array($metadata->getPublicApiScope(), ['roots', 'published'], true)
            && in_array($metadata->getPublicApiRelations(), ['none', 'links'], true);
    }

    /** @return array<string, BpNode> */
    protected function publishedNodes(BpConfig $config): array
    {
        self::validateConfiguration($config);
        return self::collectPublishedNodes($config, $config->getMetadata()->getPublicApiScope());
    }

    /** @return array<string, BpNode> */
    protected static function collectPublishedNodes(BpConfig $config, string $scope): array
    {
        $nodes = [];
        // Parent links are built lazily by BpNode::getChildren(). Materialize
        // the process graph first so public paths never depend on insertion
        // order in the stored definition.
        foreach ($config->getBpNodes() as $candidate) {
            if (get_class($candidate) === BpNode::class) {
                $candidate->getChildren();
            }
        }
        foreach ($config->getBpNodes() as $node) {
            if (get_class($node) !== BpNode::class || ! $node->getPublicStatus()) {
                continue;
            }
            if ($scope === 'roots' && ! $config->hasRootNode($node->getName())) {
                continue;
            }

            $path = self::pathForNode($config, $node);
            if (isset($nodes[$path])) {
                throw new RuntimeException('Duplicate public node path');
            }
            $nodes[$path] = $node;
            if (count($nodes) > self::MAX_PUBLIC_NODES_PER_CONFIG) {
                throw new RuntimeException('Too many public Business Process nodes');
            }
        }

        return $nodes;
    }

    protected function applyStates(BpConfig $config): void
    {
        ($this->stateLoader)($config);
    }

    protected function nodeDto(
        string $configPath,
        BpConfig $config,
        BpNode $node,
        array $publishedNodes,
        string $observedAt
    ): array {
        try {
            $status = StatusMapper::fromState($node->getState());
        } catch (Throwable $_) {
            $status = StatusMapper::UNKNOWN;
        }

        $nodePath = self::pathForNode($config, $node);
        $links = ['self' => $this->nodeUrl($configPath, $nodePath)];
        if ($config->getMetadata()->getPublicApiRelations() === 'links') {
            $links += $this->relationLinks($configPath, $node, $publishedNodes);
        }

        return [
            'status' => $status,
            'details' => [
                'name' => $node->getAlias() ?: $node->getName(),
                'path' => $configPath . '/' . $nodePath,
                'observedAt' => $observedAt,
                'links' => $links
            ]
        ];
    }

    protected function relationLinks(string $configPath, BpNode $node, array $publishedNodes): array
    {
        $byName = [];
        foreach ($publishedNodes as $publishedNode) {
            $byName[$publishedNode->getName()] = $publishedNode;
        }

        $parents = [];
        foreach ($node->getParents() as $parent) {
            if (isset($byName[$parent->getName()])) {
                $parents[] = $this->nodeUrl(
                    $configPath,
                    self::pathForNode($node->getBpConfig(), $byName[$parent->getName()])
                );
            }
        }

        $children = [];
        foreach ($node->getChildren() as $child) {
            if (isset($byName[$child->getName()])) {
                $children[] = $this->nodeUrl(
                    $configPath,
                    self::pathForNode($node->getBpConfig(), $byName[$child->getName()])
                );
            }
        }

        $links = [];
        if ($parents !== []) {
            $links['parent'] = array_values(array_unique($parents));
        }
        if ($children !== []) {
            $links['children'] = array_values(array_unique($children));
        }

        return $links;
    }

    protected function observedAt(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get())))->format(DATE_ATOM);
    }

    protected function configUrl(string $configPath): string
    {
        return '/businessprocess/health/' . rawurlencode($configPath);
    }

    protected function nodeUrl(string $configPath, string $nodePath): string
    {
        return $this->configUrl($configPath) . '/' . implode('/', array_map('rawurlencode', explode('/', $nodePath)));
    }
}
