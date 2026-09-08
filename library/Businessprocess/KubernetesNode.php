<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess;

use Icinga\Module\Businessprocess\Kubernetes\Feature;
use Icinga\Module\Businessprocess\Kubernetes\Kind;
use Icinga\Module\Businessprocess\Kubernetes\NodeName;
use Icinga\Module\Businessprocess\Kubernetes\ObjectRepository;
use Icinga\Module\Businessprocess\Web\Url;
use ipl\Html\Html;
use ipl\Web\Widget\Icon;

class KubernetesNode extends BpNode
{
    protected string $kind;

    protected string $uuid;

    protected ?string $clusterUuid = null;

    protected ?string $clusterName = null;

    protected ?string $expectedCluster = null;

    protected ?string $expectedGroup = null;

    protected ?string $expectedVersion = null;

    protected ?string $apiKind = null;

    protected ?string $namespace = null;

    protected ?string $objectName = null;

    protected bool $explicit = true;

    protected bool $expandDependencies = true;

    protected array $namespaceInclude = [];

    protected ?string $deleteReason = null;

    protected bool $unavailable = false;

    public function __construct($object)
    {
        $this->kind = Kind::canonicalize($object->kind);
        $this->uuid = $object->uuid;
        $this->namespaceInclude = Kind::DEFAULT_NAMESPACE_INCLUDE;

        parent::__construct((object) [
            'name' => NodeName::create($this->kind, $this->uuid),
            'operator' => self::OP_AND,
            'child_names' => []
        ]);
        $this->configureDependencyOperator();

        $this->alias = Kind::title($this->kind);
        $this->className = 'kubernetes ' . $this->kind;
        $this->icon = 'cubes';

        foreach ([
            'clusterUuid' => 'cluster_uuid',
            'clusterName' => 'cluster_name',
            'namespace' => 'namespace',
            'objectName' => 'object_name'
        ] as $property => $source) {
            if (isset($object->$source)) {
                $this->$property = $object->$source;
            }
        }
        if (isset($object->explicit)) {
            $this->explicit = (bool) $object->explicit;
        }
        if (isset($object->expand_dependencies)) {
            $this->expandDependencies = (bool) $object->expand_dependencies;
        }
        if (isset($object->namespace_include) && is_array($object->namespace_include)) {
            $this->namespaceInclude = $object->namespace_include;
        }
        if (isset($object->expected_cluster)) {
            $this->setExpectedCluster((string) $object->expected_cluster);
        }
        if (isset($object->expected_group, $object->expected_version, $object->api_kind)) {
            $this->setExpectedGroup((string) $object->expected_group);
            $this->setExpectedVersion((string) $object->expected_version);
            $this->setApiKind((string) $object->api_kind);
        }
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getClusterUuid(): ?string
    {
        return $this->clusterUuid;
    }

    public function getClusterName(): ?string
    {
        return $this->clusterName ?? $this->expectedCluster;
    }

    public function getExpectedCluster(): ?string
    {
        return $this->expectedCluster;
    }

    public function setExpectedCluster(string $cluster): self
    {
        if ($cluster === '' || strlen($cluster) > 253 || ! preg_match('/^[A-Za-z0-9_.-]+$/D', $cluster)) {
            throw new \InvalidArgumentException('Invalid Kubernetes cluster name');
        }
        $this->expectedCluster = $cluster;

        return $this;
    }

    public function getNamespace(): ?string
    {
        return $this->namespace;
    }

    public function getObjectName(): ?string
    {
        return $this->objectName;
    }

    public function getStoredAlias(): string
    {
        return parent::getAlias();
    }

    public function getAlias()
    {
        $alias = parent::getAlias();
        if ($this->deleteReason !== null) {
            return sprintf('%s (%s)', $alias, $this->deleteReason);
        }

        return $alias;
    }

    public function getLabel()
    {
        return $this->getAlias();
    }

    public function isEmpty()
    {
        return false;
    }

    public function isExplicit(): bool
    {
        return $this->explicit;
    }

    public function setExplicit(bool $explicit): self
    {
        $this->explicit = $explicit;

        return $this;
    }

    public function getExpandDependencies(): bool
    {
        return $this->expandDependencies;
    }

    public function setExpandDependencies(bool $expand): self
    {
        $this->expandDependencies = $expand;

        return $this;
    }

    public function getNamespaceInclude(): array
    {
        return $this->namespaceInclude;
    }

    public function setNamespaceInclude(array $include): self
    {
        $this->namespaceInclude = array_values(array_intersect($include, Kind::NAMESPACE_INCLUDE_OPTIONS));

        return $this;
    }

    public function hasInfoUrl()
    {
        return true;
    }

    public function getInfoUrl()
    {
        return $this->getUrl();
    }

    public function getDeleteReason(): ?string
    {
        return $this->deleteReason;
    }

    public function markDeleted(string $reason = 'Object no longer exists'): self
    {
        $this->unavailable = false;
        $this->deleteReason = $reason;
        $this->setState(self::ICINGA_CRITICAL);
        $this->setMissing();

        return $this;
    }

    public function getState()
    {
        if ($this->unavailable) {
            return self::ICINGA_UNKNOWN;
        }

        if ($this->deleteReason === null && $this->usesDependencyState()) {
            return parent::getState();
        }

        if (Kind::hasState($this->kind)) {
            return $this->state ?? self::ICINGA_UNKNOWN;
        }

        if ($this->hasChildren()) {
            return parent::getState();
        }

        return $this->state ?? self::ICINGA_OK;
    }

    public function addChild(Node $node)
    {
        parent::addChild($node);
        $this->configureDependencyOperator();
        $this->clearState();

        return $this;
    }

    public function getExpectedGroup(): ?string
    {
        return $this->expectedGroup;
    }

    public function setExpectedGroup(string $group): self
    {
        if (strlen($group) > 512 || str_contains($group, "\0")) {
            throw new \InvalidArgumentException('Invalid Kubernetes API group');
        }
        $this->expectedGroup = $group;
        return $this;
    }

    public function getExpectedVersion(): ?string
    {
        return $this->expectedVersion;
    }

    public function setExpectedVersion(string $version): self
    {
        if ($version === '' || strlen($version) > 512 || str_contains($version, "\0")) {
            throw new \InvalidArgumentException('Invalid Kubernetes API version');
        }
        $this->expectedVersion = $version;
        return $this;
    }

    public function getApiKind(): string
    {
        return $this->apiKind ?? Kind::title($this->kind);
    }

    public function setApiKind(string $kind): self
    {
        if ($kind === '' || strlen($kind) > 128 || ! preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $kind)) {
            throw new \InvalidArgumentException('Invalid Kubernetes API kind');
        }
        $this->apiKind = $kind;
        return $this;
    }

    public function markUnavailable(): self
    {
        // A transport/backend failure does not prove that the Kubernetes
        // object disappeared. Keep that distinct from markDeleted(), so a
        // short outage can never turn into a false CRITICAL deletion alarm.
        $this->unavailable = true;
        $this->deleteReason = null;
        $this->setState(self::ICINGA_UNKNOWN);
        $this->setMissing(false);

        return $this;
    }

    public function getLink()
    {
        $attributes = ['href' => $this->isMissing() ? '#' : $this->getUrl()];
        if ($this->deleteReason !== null) {
            $attributes['title'] = $this->deleteReason;
        }

        if (! Feature::isAvailable()) {
            $attributes['href'] = '#';
        }

        return Html::tag('a', $attributes, $this->getAlias());
    }

    public function getUrl()
    {
        return Url::fromPath('kubernetes/resources/show', [
            'id' => $this->uuid,
            'cluster' => $this->getClusterName()
        ]);
    }

    public function getIcon(): Icon
    {
        return new Icon(match ($this->kind) {
            'namespace' => 'sitemap',
            'pod' => 'box',
            'container', 'initcontainer', 'sidecarcontainer' => 'cube',
            'service' => 'exchange-alt',
            'ingress' => 'share-alt',
            'configmap' => 'file-code',
            'secret' => 'lock',
            default => 'cubes'
        });
    }

    public function refreshFromKubernetes(): self
    {
        if (! Feature::isEnabled()) {
            return $this->markUnavailable();
        }

        $object = ObjectRepository::fetch(
            $this->getApiKind(),
            $this->uuid,
            $this->expectedCluster,
            $this->expectedGroup,
            $this->expectedVersion
        );
        if ($object === null) {
            return $this->markDeleted('Object no longer exists');
        }

        $this->unavailable = false;
        $this->deleteReason = null;
        $this->clusterUuid = ObjectRepository::clusterUuidFor($this->kind, $object);
        $this->clusterName = $this->clusterUuid !== null ? ObjectRepository::clusterName($this->clusterUuid) : null;
        $this->namespace = ObjectRepository::namespaceFor($this->kind, $object);
        $this->objectName = $object->name ?? null;
        $this->setAlias(implode(' / ', ObjectRepository::labelParts($this->kind, $object)));

        if (in_array($object->freshness ?? 'live', ['stale', 'unavailable'], true)) {
            // Dependency-backed workload nodes normally derive their state
            // from their children. Mark the whole node unavailable here so
            // stale source data cannot be hidden by still-green child state.
            return $this->markUnavailable();
        }

        if (! in_array($this->kind, ['configmap', 'secret'], true)) {
            if ($this->usesDependencyState()) {
                $this->clearState();
            } else {
                $this->setState(self::mapKubernetesState($object->icinga_state ?? 'unknown'));
            }
        } elseif (in_array($this->kind, ['configmap', 'secret'], true)) {
            $this->setState(self::ICINGA_OK);
        }

        $this->setMissing(false);

        return $this;
    }

    protected function usesDependencyState(): bool
    {
        return $this->hasChildren()
            && in_array($this->kind, ['deployment', 'replicaset', 'statefulset'], true);
    }

    protected function configureDependencyOperator(): void
    {
        if (in_array($this->kind, ['deployment', 'replicaset'], true)) {
            $this->setOperator(self::OP_OR);
        } elseif ($this->kind === 'statefulset') {
            $this->setOperator(min(2, max(1, $this->countChildren())));
        }
    }

    public static function mapKubernetesState(string $state): int
    {
        return match ($state) {
            'ok'       => self::ICINGA_OK,
            'warning'  => self::ICINGA_WARNING,
            'critical' => self::ICINGA_CRITICAL,
            'pending'  => self::ICINGA_PENDING,
            default    => self::ICINGA_UNKNOWN
        };
    }
}
