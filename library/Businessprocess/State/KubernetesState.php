<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\State;

use Exception;
use Icinga\Module\Businessprocess\BpConfig;
use Icinga\Module\Businessprocess\Kubernetes\DependencyResolver;
use Icinga\Module\Businessprocess\Kubernetes\Feature;
use Icinga\Module\Businessprocess\KubernetesNode;

class KubernetesState
{
    public static function apply(BpConfig $config): void
    {
        $hasKubernetesNodes = false;
        foreach ($config->getNodes() as $node) {
            if ($node instanceof KubernetesNode) {
                $hasKubernetesNodes = true;
                break;
            }
        }

        if (! $hasKubernetesNodes) {
            return;
        }

        try {
            if (Feature::isEnabled()) {
                DependencyResolver::apply($config);
            }
        } catch (Exception $e) {
            $config->addError($config->translate('Could not resolve Kubernetes dependencies: %s'), $e->getMessage());
        }

        foreach ($config->getNodes() as $node) {
            if (! $node instanceof KubernetesNode) {
                continue;
            }

            try {
                $node->refreshFromKubernetes();
            } catch (Exception $e) {
                $node->markDeleted($e->getMessage());
            }
        }
    }
}