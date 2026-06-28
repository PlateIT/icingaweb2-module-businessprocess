<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\Kubernetes;

use Icinga\Module\Kubernetes\Common\Auth as KubernetesAuth;
use Icinga\Module\Kubernetes\Common\Database as KubernetesDatabase;
use Icinga\Module\Kubernetes\Model\Cluster;
use Icinga\Module\Kubernetes\Model\ConfigMap;
use Icinga\Module\Kubernetes\Model\Container;
use Icinga\Module\Kubernetes\Model\CronJob;
use Icinga\Module\Kubernetes\Model\DaemonSet;
use Icinga\Module\Kubernetes\Model\Deployment;
use Icinga\Module\Kubernetes\Model\Ingress;
use Icinga\Module\Kubernetes\Model\InitContainer;
use Icinga\Module\Kubernetes\Model\Job;
use Icinga\Module\Kubernetes\Model\NamespaceModel;
use Icinga\Module\Kubernetes\Model\PersistentVolumeClaim;
use Icinga\Module\Kubernetes\Model\Pod;
use Icinga\Module\Kubernetes\Model\ReplicaSet;
use Icinga\Module\Kubernetes\Model\Secret;
use Icinga\Module\Kubernetes\Model\Service;
use Icinga\Module\Kubernetes\Model\SidecarContainer;
use Icinga\Module\Kubernetes\Model\StatefulSet;
use ipl\Orm\Model;
use ipl\Sql\Select;
use ipl\Stdlib\Filter;
use Ramsey\Uuid\Uuid;

class ObjectRepository
{
    protected static array $clusterNames = [];

    public static function createModel(string $kind): ?Model
    {
        return match (Kind::canonicalize($kind)) {
            'namespace'             => new NamespaceModel(),
            'deployment'            => new Deployment(),
            'replicaset'            => new ReplicaSet(),
            'statefulset'           => new StatefulSet(),
            'daemonset'             => new DaemonSet(),
            'cronjob'               => new CronJob(),
            'job'                   => new Job(),
            'pod'                   => new Pod(),
            'container'             => new Container(),
            'initcontainer'         => new InitContainer(),
            'sidecarcontainer'      => new SidecarContainer(),
            'service'               => new Service(),
            'ingress'               => new Ingress(),
            'persistentvolumeclaim' => new PersistentVolumeClaim(),
            'configmap'             => new ConfigMap(),
            'secret'                => new Secret(),
            default                 => null
        };
    }

    public static function canAccessAny(): bool
    {
        $auth = KubernetesAuth::getInstance();
        foreach (Kind::SUPPORTED as $kind) {
            if (isset(KubernetesAuth::PERMISSIONS[$kind]) && $auth->canList($kind)) {
                return true;
            }
        }

        return false;
    }

    public static function fetch(string $kind, string $uuid): ?object
    {
        $kind = Kind::canonicalize($kind);
        $model = self::createModel($kind);
        if ($model === null) {
            return null;
        }

        $table = $model->getTableName();
        $query = $model::on(KubernetesDatabase::connection())
            ->columns(self::columnsFor($kind))
            ->filter(Filter::equal("$table.uuid", Uuid::fromString($uuid)->getBytes()));

        return self::applyRestrictions($kind, $query)->first();
    }

    public static function search(string $kind, string $term, int $limit = 50): iterable
    {
        $kind = Kind::canonicalize($kind);
        $model = self::createModel($kind);
        if ($model === null) {
            return [];
        }

        $table = $model->getTableName();
        $query = $model::on(KubernetesDatabase::connection())
            ->columns(self::columnsFor($kind))
            ->limit($limit);

        $search = Filter::any(
            Filter::like("$table.name", $term),
            Filter::equal("$table.name", $term)
        );

        $clusterUuidColumn = "$table.cluster_uuid";
        if (self::isContainerKind($kind)) {
            $query->getSelectBase()->join('pod', "pod.uuid = $table.pod_uuid");
            $clusterUuidColumn = 'pod.cluster_uuid';
            $search->add(Filter::like('pod.namespace', $term));
            $search->add(Filter::equal('pod.namespace', $term));
        } elseif ($kind !== 'namespace') {
            $search->add(Filter::like("$table.namespace", $term));
            $search->add(Filter::equal("$table.namespace", $term));
        }

        foreach (self::searchClusterUuids($term) as $clusterUuid) {
            $search->add(Filter::equal($clusterUuidColumn, $clusterUuid));
        }

        $query->filter($search);

        return self::applyRestrictions($kind, $query);
    }

    public static function labelParts(string $kind, object $object): array
    {
        $kind = Kind::canonicalize($kind);
        $parts = [Kind::title($kind)];
        $clusterUuid = self::clusterUuidFor($kind, $object);
        if ($clusterUuid !== null) {
            $parts[] = self::clusterName($clusterUuid);
        }

        $namespace = self::namespaceFor($kind, $object);
        if ($namespace !== null && $namespace !== '') {
            $parts[] = $namespace;
        }

        if (isset($object->name)) {
            $parts[] = $object->name;
        }

        return $parts;
    }

    public static function clusterUuidFor(string $kind, object $object): ?string
    {
        if (isset($object->cluster_uuid)) {
            return self::uuidToString($object->cluster_uuid);
        }

        if (self::isContainerKind($kind) && isset($object->pod_uuid)) {
            $pod = self::fetch('pod', self::uuidToString($object->pod_uuid));
            if ($pod !== null && isset($pod->cluster_uuid)) {
                return self::uuidToString($pod->cluster_uuid);
            }
        }

        return null;
    }

    public static function namespaceFor(string $kind, object $object): ?string
    {
        if (isset($object->namespace)) {
            return $object->namespace;
        }

        if (self::isContainerKind($kind) && isset($object->pod_uuid)) {
            $pod = self::fetch('pod', self::uuidToString($object->pod_uuid));
            if ($pod !== null && isset($pod->namespace)) {
                return $pod->namespace;
            }
        }

        return null;
    }

    public static function clusterName(string $clusterUuid): string
    {
        if (! array_key_exists($clusterUuid, self::$clusterNames)) {
            $cluster = Cluster::on(KubernetesDatabase::connection())
                ->columns(['uuid', 'name'])
                ->filter(Filter::equal('cluster.uuid', Uuid::fromString($clusterUuid)->getBytes()))
                ->first();
            self::$clusterNames[$clusterUuid] = $cluster->name ?? $clusterUuid;
        }

        return self::$clusterNames[$clusterUuid];
    }

    public static function children(string $kind, string $uuid, array $namespaceInclude = []): iterable
    {
        $kind = Kind::canonicalize($kind);

        return match ($kind) {
            'namespace' => self::namespaceChildren($uuid, $namespaceInclude),
            'deployment' => self::ownedBy('replicaset', $uuid),
            'replicaset' => self::ownedBy('pod', $uuid),
            'statefulset' => self::ownedBy('pod', $uuid),
            'daemonset' => self::ownedBy('pod', $uuid),
            'cronjob' => self::ownedBy('job', $uuid),
            'job' => self::ownedBy('pod', $uuid),
            'pod' => self::podChildren($uuid),
            default => []
        };
    }

    protected static function namespaceChildren(string $uuid, array $include): iterable
    {
        $namespace = self::fetch('namespace', $uuid);
        if ($namespace === null) {
            return [];
        }

        $include = $include ?: Kind::DEFAULT_NAMESPACE_INCLUDE;
        $children = [];
        foreach ($include as $option) {
            foreach (self::namespaceOption($namespace, $option) as $child) {
                $children[] = $child;
            }
        }

        return $children;
    }

    protected static function namespaceOption(object $namespace, string $option): iterable
    {
        return match ($option) {
            'deployment' => self::inNamespace('deployment', $namespace),
            'statefulset' => self::inNamespace('statefulset', $namespace),
            'daemonset' => self::inNamespace('daemonset', $namespace),
            'cronjob' => self::inNamespace('cronjob', $namespace),
            'standalone_job' => self::standaloneInNamespace('job', 'job_owner', 'job_uuid', $namespace),
            'standalone_replicaset' => self::standaloneInNamespace('replicaset', 'replica_set_owner', 'replica_set_uuid', $namespace),
            'standalone_pod' => self::standaloneInNamespace('pod', 'pod_owner', 'pod_uuid', $namespace),
            'service' => self::inNamespace('service', $namespace),
            'ingress' => self::inNamespace('ingress', $namespace),
            'persistentvolumeclaim' => self::inNamespace('persistentvolumeclaim', $namespace),
            'configmap' => self::inNamespace('configmap', $namespace),
            'secret' => self::inNamespace('secret', $namespace),
            default => []
        };
    }

    protected static function inNamespace(string $kind, object $namespace): iterable
    {
        $model = self::createModel($kind);
        $table = $model->getTableName();
        $query = $model::on(KubernetesDatabase::connection())
            ->columns(self::columnsFor($kind))
            ->filter(Filter::all(
                Filter::equal("$table.cluster_uuid", $namespace->cluster_uuid),
                Filter::equal("$table.namespace", $namespace->name)
            ));

        return self::asChildList($kind, self::applyRestrictions($kind, $query));
    }

    protected static function standaloneInNamespace(string $kind, string $ownerTable, string $foreignKey, object $namespace): iterable
    {
        $children = [];
        foreach (self::inNamespace($kind, $namespace) as $child) {
            $select = (new Select())
                ->from($ownerTable)
                ->columns([$foreignKey])
                ->where(["$foreignKey = ?" => Uuid::fromString($child['uuid'])->getBytes()]);
            $hasOwner = false;
            foreach (KubernetesDatabase::connection()->select($select) as $_) {
                $hasOwner = true;
                break;
            }
            if (! $hasOwner) {
                $children[] = $child;
            }
        }

        return $children;
    }

    protected static function ownedBy(string $kind, string $ownerUuid): iterable
    {
        $model = self::createModel($kind);
        $query = $model::on(KubernetesDatabase::connection())
            ->columns(self::columnsFor($kind))
            ->filter(Filter::equal('owner.owner_uuid', Uuid::fromString($ownerUuid)->getBytes()));

        return self::asChildList($kind, self::applyRestrictions($kind, $query));
    }

    protected static function podChildren(string $podUuid): iterable
    {
        $children = [];
        foreach (['initcontainer', 'container', 'sidecarcontainer'] as $kind) {
            $model = self::createModel($kind);
            $table = $model->getTableName();
            $query = $model::on(KubernetesDatabase::connection())
                ->columns(self::columnsFor($kind))
                ->filter(Filter::equal("$table.pod_uuid", Uuid::fromString($podUuid)->getBytes()));
            $children = array_merge($children, self::asChildList($kind, $query));
        }

        return $children;
    }

    protected static function searchClusterUuids(string $term): array
    {
        $uuids = [];
        $query = Cluster::on(KubernetesDatabase::connection())
            ->columns(['uuid'])
            ->filter(Filter::any(
                Filter::like('cluster.name', $term),
                Filter::equal('cluster.name', $term)
            ))
            ->limit(50);

        foreach ($query as $cluster) {
            $uuids[] = $cluster->uuid;
        }

        if (Uuid::isValid($term)) {
            $uuids[] = Uuid::fromString($term)->getBytes();
        }

        return $uuids;
    }

    protected static function asChildList(string $kind, iterable $query): array
    {
        $children = [];
        foreach ($query as $row) {
            $children[] = [
                'kind' => Kind::canonicalize($kind),
                'uuid' => self::uuidToString($row->uuid)
            ];
        }

        return $children;
    }

    public static function uuidToString($uuid): string
    {
        return (string) Uuid::fromBytes(is_resource($uuid) ? stream_get_contents($uuid) : $uuid);
    }

    protected static function columnsFor(string $kind): array
    {
        $kind = Kind::canonicalize($kind);

        if (self::isContainerKind($kind)) {
            return ['uuid', 'pod_uuid', 'name', 'icinga_state', 'icinga_state_reason'];
        }

        $columns = ['uuid', 'cluster_uuid', 'namespace', 'name'];
        if (Kind::hasState($kind)) {
            $columns[] = 'icinga_state';
            $columns[] = 'icinga_state_reason';
        }

        return $columns;
    }

    protected static function isContainerKind(string $kind): bool
    {
        return in_array(Kind::canonicalize($kind), ['container', 'initcontainer', 'sidecarcontainer'], true);
    }

    protected static function applyRestrictions(string $kind, $query)
    {
        if (isset(KubernetesAuth::PERMISSIONS[$kind])) {
            return KubernetesAuth::getInstance()->withRestrictions(KubernetesAuth::PERMISSIONS[$kind], $query);
        }

        return $query;
    }
}

