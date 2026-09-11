<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\Kubernetes;

use Ramsey\Uuid\Uuid;

class NodeName
{
    public const PREFIX = 'kubernetes:';

    public static function create(string $kind, string $uuid): string
    {
        return self::PREFIX . Kind::canonicalize($kind) . ':' . $uuid;
    }

    public static function isKubernetesNodeName(string $name): bool
    {
        return str_starts_with($name, self::PREFIX);
    }

    public static function parse(string $name): ?array
    {
        if (! self::isKubernetesNodeName($name)) {
            return null;
        }

        $parts = explode(':', $name, 3);
        if (count($parts) !== 3 || $parts[0] !== 'kubernetes') {
            return null;
        }

        $kind = Kind::canonicalize($parts[1]);
        if (! Kind::isSupported($kind) || ! Uuid::isValid($parts[2])) {
            return null;
        }

        return [$kind, $parts[2]];
    }
}
