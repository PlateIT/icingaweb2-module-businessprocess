<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\PublicHealth;

use Icinga\Module\Businessprocess\Node;

class StatusMapper
{
    public const UP = 'UP';
    public const DEGRADED = 'DEGRADED';
    public const DOWN = 'DOWN';
    public const UNKNOWN = 'UNKNOWN';

    protected const WEIGHT = [
        self::UP => 0,
        self::DEGRADED => 1,
        self::DOWN => 2,
        self::UNKNOWN => 3
    ];

    public static function fromState(int $state): string
    {
        return match ($state) {
            Node::ICINGA_OK => self::UP,
            Node::ICINGA_WARNING => self::DEGRADED,
            Node::ICINGA_CRITICAL => self::DOWN,
            default => self::UNKNOWN
        };
    }

    public static function aggregate(array $statuses): string
    {
        if ($statuses === []) {
            return self::UNKNOWN;
        }

        return array_reduce($statuses, function (string $worst, string $status): string {
            return self::WEIGHT[$status] > self::WEIGHT[$worst] ? $status : $worst;
        }, self::UP);
    }

    public static function httpStatus(string $status): int
    {
        return in_array($status, [self::DOWN, self::UNKNOWN], true) ? 503 : 200;
    }
}
