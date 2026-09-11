<?php

// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess;

use Icinga\Module\Businessprocess\Kubernetes\ApiClient;
use Icinga\Module\Businessprocess\Kubernetes\Feature;
use Icinga\Module\Businessprocess\Kubernetes\Kind;
use Icinga\Module\Businessprocess\Kubernetes\NodeName;
use Icinga\Module\Businessprocess\Kubernetes\ObjectRepository;
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
        return $this->applyResolution($result);
    }

    public function applyResolution(array $result): self
    {
        $this->detachMatches();
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
            || ! is_array($result['items'] ?? null)
            || ! array_is_list($result['items'])
            || count($result['items']) > $result['matchedCount']
        ) {
            return $this->markUnavailable();
        }
        $objects = ObjectRepository::rememberSelection($result['items'], $this->freshness);
        $config = $this->getBpConfig();
        foreach ($objects as $object) {
            $name = NodeName::create($object->kind, $object->uuid);
            $child = $config->hasNode($name) ? $config->getNode($name) : null;
            if (! $child instanceof KubernetesNode) {
                $child = $config->createKubernetesNode($object->kind, $object->uuid, false);
                $child->setExpectedCluster($object->cluster_name);
                $child->setExpectedGroup($object->group);
                $child->setExpectedVersion($object->version);
                $child->setApiKind($object->api_kind);
                $child->setExpandDependencies(Kind::hasDependencies($object->kind));
            }
            if (! $this->hasChild($name)) {
                $this->addChild($child);
            }
        }
        $this->setState(KubernetesNode::mapKubernetesState($result['state']));
        return $this;
    }

    public function markUnavailable(): self
    {
        $this->detachMatches();
        $this->freshness = 'unavailable';
        $this->setState(self::ICINGA_UNKNOWN);

        return $this;
    }

    protected function detachMatches(): void
    {
        foreach ($this->getChildren() as $child) {
            $child->removeParent($this->getName());
            $this->removeChild($child->getName());
        }
    }

    public function getState()
    {
        // The API aggregates all matches, including those beyond its item limit.
        return $this->state ?? self::ICINGA_UNKNOWN;
    }

    public function reCalculateState()
    {
        // A selector is an external state source, even when it has no matches.
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
