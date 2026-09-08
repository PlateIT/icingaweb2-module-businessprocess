<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\Kubernetes;

use Icinga\Exception\ConfigurationError;
use Icinga\Module\Businessprocess\BpConfig;
use Icinga\Module\Businessprocess\KubernetesNode;

class DependencyResolver
{
    protected BpConfig $config;

    public function __construct(BpConfig $config)
    {
        $this->config = $config;
    }

    public static function apply(BpConfig $config): void
    {
        if (! Feature::isAvailable()) {
            return;
        }

        (new self($config))->resolve();
    }

    public function resolve(): void
    {
        $queue = array_values(array_filter(
            $this->config->getNodes(),
            fn($node) => $node instanceof KubernetesNode
        ));

        $seen = [];
        $changed = false;
        while ($queue !== []) {
            $batch = [];
            foreach ($queue as $node) {
                /** @var KubernetesNode $node */
                if (isset($seen[$node->getName()])) {
                    continue;
                }
                $seen[$node->getName()] = true;

                if ($node->getExpandDependencies() && Kind::hasDependencies($node->getKind())) {
                    $batch[] = $node;
                }
            }
            $queue = [];

            $childrenByParent = ObjectRepository::childrenMany(array_map(
                fn(KubernetesNode $node) => [
                    'kind' => $node->getKind(),
                    'uuid' => $node->getUuid(),
                    'namespace_include' => $node->getNamespaceInclude()
                ],
                $batch
            ));

            foreach ($batch as $node) {
                $key = Kind::canonicalize($node->getKind()) . ':' . $node->getUuid();
                foreach ($childrenByParent[$key] ?? [] as $childInfo) {
                    $child = $this->requireImplicitNode($childInfo['kind'], $childInfo['uuid']);
                    if (! $node->hasChild($child->getName())) {
                        try {
                            $node->addChild($child);
                            $changed = true;
                        } catch (ConfigurationError $_) {
                            // Duplicate children can occur when Kubernetes owner chains race during refresh.
                        }
                    }
                    $queue[] = $child;
                }
            }
        }

        if ($changed) {
            foreach ($this->config->getBpNodes() as $bpNode) {
                if (! $bpNode instanceof KubernetesNode) {
                    $bpNode->clearState();
                }
            }
        }
    }

    protected function requireImplicitNode(string $kind, string $uuid): KubernetesNode
    {
        $name = NodeName::create($kind, $uuid);
        if ($this->config->hasNode($name)) {
            $node = $this->config->getNode($name);
            if ($node instanceof KubernetesNode) {
                return $node;
            }
        }

        $node = $this->config->createKubernetesNode($kind, $uuid, false);
        $node->setExpandDependencies(Kind::hasDependencies($kind));

        return $node;
    }
}
