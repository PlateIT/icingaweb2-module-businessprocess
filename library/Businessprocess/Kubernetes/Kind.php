<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\Kubernetes;

class Kind
{
    public const SUPPORTED = [
        'namespace',
        'deployment',
        'replicaset',
        'statefulset',
        'daemonset',
        'cronjob',
        'job',
        'pod',
        'container',
        'initcontainer',
        'sidecarcontainer',
        'service',
        'ingress',
        'persistentvolumeclaim',
        'configmap',
        'secret'
    ];

    public const DEFAULT_NAMESPACE_INCLUDE = [
        'deployment',
        'statefulset',
        'daemonset',
        'cronjob',
        'standalone_job',
        'standalone_replicaset',
        'standalone_pod'
    ];

    public const NAMESPACE_INCLUDE_OPTIONS = [
        'deployment',
        'statefulset',
        'daemonset',
        'cronjob',
        'standalone_job',
        'standalone_replicaset',
        'standalone_pod'
    ];

    public static function canonicalize(string $kind): string
    {
        $kind = strtolower($kind);

        return match ($kind) {
            'config_map'             => 'configmap',
            'cron_job'               => 'cronjob',
            'daemon_set'             => 'daemonset',
            'init_container'         => 'initcontainer',
            'persistent_volume_claim', 'pvc' => 'persistentvolumeclaim',
            'replica_set'            => 'replicaset',
            'sidecar_container'      => 'sidecarcontainer',
            'stateful_set'           => 'statefulset',
            default                  => str_replace(['_', '-'], '', $kind)
        };
    }

    public static function isSupported(string $kind): bool
    {
        $kind = self::canonicalize($kind);
        return $kind !== '' && strlen($kind) <= 128 && preg_match('/^[a-z][a-z0-9]*$/D', $kind) === 1;
    }

    public static function hasState(string $kind): bool
    {
        return in_array(self::canonicalize($kind), [
            'deployment',
            'replicaset',
            'statefulset',
            'daemonset',
            'cronjob',
            'job',
            'pod',
            'container',
            'initcontainer',
            'sidecarcontainer'
        ], true);
    }

    public static function hasDependencies(string $kind): bool
    {
        return in_array(self::canonicalize($kind), [
            'namespace',
            'deployment',
            'replicaset',
            'statefulset',
            'daemonset',
            'cronjob',
            'job',
            'pod'
        ], true);
    }

    public static function title(string $kind): string
    {
        return match (self::canonicalize($kind)) {
            'namespace'             => 'Namespace',
            'deployment'            => 'Deployment',
            'replicaset'            => 'ReplicaSet',
            'statefulset'           => 'StatefulSet',
            'daemonset'             => 'DaemonSet',
            'cronjob'               => 'CronJob',
            'job'                   => 'Job',
            'pod'                   => 'Pod',
            'container'             => 'Container',
            'initcontainer'         => 'InitContainer',
            'sidecarcontainer'      => 'SidecarContainer',
            'service'               => 'Service',
            'ingress'               => 'Ingress',
            'persistentvolumeclaim' => 'PersistentVolumeClaim',
            'configmap'             => 'ConfigMap',
            'secret'                => 'Secret',
            default                 => ucfirst($kind)
        };
    }
}
