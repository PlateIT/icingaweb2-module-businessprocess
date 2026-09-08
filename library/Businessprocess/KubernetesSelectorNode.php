<?php

// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess;

use Icinga\Module\Businessprocess\Kubernetes\ApiClient;
use Icinga\Module\Businessprocess\Kubernetes\Feature;
use Icinga\Module\Businessprocess\Web\Url;
use InvalidArgumentException;

class KubernetesSelectorNode extends BpNode
{
    protected array $selector = [];
    protected string $aggregation = 'worst';
    protected string $freshness = 'unknown';

    public function __construct(string $name)
    {
        parent::__construct((object) ['name' => $name, 'operator' => self::OP_AND, 'child_names' => []]);
        $this->alias = 'Kubernetes selector';
        $this->className = 'kubernetes selector';
        $this->icon = 'filter';
    }

    public function setSelector(array $selector): self
    {
        $allowed = ['cluster', 'group', 'version', 'kind', 'namespace', 'name', 'labels', 'ownerUID', 'states'];
        if (array_diff(array_keys($selector), $allowed) !== []) {
            throw new InvalidArgumentException('Kubernetes selector contains unknown fields');
        }
        $this->selector = $selector;
        return $this;
    }

    public function getSelector(): array
    {
        return $this->selector;
    }

    public function setAggregation(string $aggregation): self
    {
        if (! in_array($aggregation, ['and', 'or', 'worst'], true)) {
            throw new InvalidArgumentException('Invalid Kubernetes selector aggregation');
        }
        $this->aggregation = $aggregation;
        return $this;
    }

    public function getAggregation(): string
    {
        return $this->aggregation;
    }

    public function refreshFromKubernetes(): self
    {
        if (! Feature::isEnabled()) {
            return $this->markUnavailable();
        }

        $result = (new ApiClient())->post('selectors/resolve', $this->selector + ['aggregation' => $this->aggregation]);
        $this->freshness = (string) ($result['freshness'] ?? 'unknown');
        if ($this->freshness !== 'live') {
            $this->setState(self::ICINGA_UNKNOWN);
            return $this;
        }
        if (! isset($result['state'], $result['matchedCount'])
            || ! is_string($result['state'])
            || ! in_array($result['state'], ['ok', 'warning', 'critical', 'unknown'], true)
            || ! is_int($result['matchedCount'])
            || $result['matchedCount'] < 0
        ) {
            return $this->markUnavailable();
        }
        $this->setState(KubernetesNode::mapKubernetesState($result['state']));
        return $this;
    }

    public function markUnavailable(): self
    {
        $this->freshness = 'unavailable';
        $this->setState(self::ICINGA_UNKNOWN);

        return $this;
    }

    public function getFreshness(): string
    {
        return $this->freshness;
    }

    public function getUrl()
    {
        return Url::fromPath('kubernetes/resources', $this->selector);
    }

    public function isEmpty()
    {
        return false;
    }
}
