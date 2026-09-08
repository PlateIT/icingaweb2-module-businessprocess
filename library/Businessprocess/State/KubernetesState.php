<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\State;

use Icinga\Module\Businessprocess\BpConfig;
use Icinga\Module\Businessprocess\Kubernetes\DependencyResolver;
use Icinga\Module\Businessprocess\Kubernetes\Feature;
use Icinga\Module\Businessprocess\Kubernetes\ObjectRepository;
use Icinga\Module\Businessprocess\KubernetesNode;
use Icinga\Module\Businessprocess\KubernetesSelectorNode;
use Throwable;

class KubernetesState
{
    public static function apply(BpConfig $config): void
    {
        $hasKubernetesNodes = false;
        $fixedNodes = [];
        foreach ($config->getNodes() as $node) {
            if ($node instanceof KubernetesNode || $node instanceof KubernetesSelectorNode) {
                $hasKubernetesNodes = true;
            }
            if ($node instanceof KubernetesNode) {
                $fixedNodes[] = $node;
            }
        }

        if (! $hasKubernetesNodes) {
            return;
        }

        ObjectRepository::beginCalculation();
        $fixedAvailable = Feature::isEnabled();
        if ($fixedAvailable) {
            try {
                ObjectRepository::preload(array_map(
                    static fn(KubernetesNode $node): array => [
                        'kind' => $node->getKind(),
                        'uuid' => $node->getUuid(),
                        'cluster' => $node->getExpectedCluster(),
                        'group' => $node->getExpectedGroup(),
                        'version' => $node->getExpectedVersion()
                    ],
                    $fixedNodes
                ));
            } catch (Throwable $e) {
                $fixedAvailable = false;
                $config->addError($config->translate('Could not load Kubernetes objects: %s'), $e->getMessage());
            }
            if ($fixedAvailable) {
                try {
                    DependencyResolver::apply($config);
                } catch (Throwable $e) {
                    $fixedAvailable = false;
                    $config->addError(
                        $config->translate('Could not resolve Kubernetes dependencies: %s'),
                        $e->getMessage()
                    );
                }
            }
        }

        foreach ($config->getNodes() as $node) {
            if (! ($node instanceof KubernetesNode || $node instanceof KubernetesSelectorNode)) {
                continue;
            }

            if ($node instanceof KubernetesNode && ! $fixedAvailable) {
                $node->markUnavailable();
                continue;
            }

            try {
                $node->refreshFromKubernetes();
            } catch (Throwable $_) {
                $node->markUnavailable();
            }
        }
    }
}
