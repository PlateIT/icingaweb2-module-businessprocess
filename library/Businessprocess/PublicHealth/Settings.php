<?php

// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\PublicHealth;

use Icinga\Application\Config;

final class Settings
{
    public static function isEnabled(): bool
    {
        $configured = getenv('ICINGA_BUSINESSPROCESS_PUBLIC_HEALTH_ENABLED');
        if ($configured === false || $configured === '') {
            $configured = (string) Config::module('businessprocess')
                ->getSection('general')->get('public_health_enabled', 'no');
        }
        return in_array(strtolower($configured), ['1', 'true', 'yes', 'on'], true);
    }
}
