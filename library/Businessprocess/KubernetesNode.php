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

    protected ?string $namespace = null;

    protected ?string $objectName = null;

    protected bool $explicit = true;

    protected bool $expandDependencies = true;

    protected array $namespaceInclude = [];

    protected ?string $deleteReason = null;

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
        return $this->clusterName;
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

    public function isExplicit(): bool
    {
        return $this->explicit;
    }

    public function setExplicit(bool $explicit): self
    {
        $this->explicit = $explicit;

        return $this;
    }

    public function expandsDependencies(): bool
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
        $this->deleteReason = $reason;
        $this->setState(self::ICINGA_CRITICAL);
        $this->setMissing();

        return $this;
    }

    public function getState()
    {
        if (Kind::hasState($this->kind) || in_array($this->kind, ['configmap', 'secret'], true)) {
            return $this->state ?? self::ICINGA_UNKNOWN;
        }

        return parent::getState();
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
        $path = match ($this->kind) {
            'persistentvolumeclaim' => 'kubernetes/pvc',
            default => 'kubernetes/' . $this->kind
        };

        return Url::fromPath($path, ['id' => $this->uuid]);
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
            return $this->markDeleted('Kubernetes module disabled');
        }

        $object = ObjectRepository::fetch($this->kind, $this->uuid);
        if ($object === null) {
            return $this->markDeleted('Object no longer exists');
        }

        $this->deleteReason = null;
        $this->clusterUuid = ObjectRepository::clusterUuidFor($this->kind, $object);
        $this->clusterName = $this->clusterUuid !== null ? ObjectRepository::clusterName($this->clusterUuid) : null;
        $this->namespace = ObjectRepository::namespaceFor($this->kind, $object);
        $this->objectName = $object->name ?? null;
        $this->setAlias(implode(' / ', ObjectRepository::labelParts($this->kind, $object)));

        if (Kind::hasState($this->kind)) {
            $this->setState(self::mapKubernetesState($object->icinga_state ?? 'unknown'));
        } elseif (in_array($this->kind, ['configmap', 'secret'], true)) {
            $this->setState(self::ICINGA_OK);
        }

        $this->setMissing(false);

        return $this;
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


