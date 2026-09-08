<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\Kubernetes;

use Icinga\Application\Config;
use Icinga\Exception\ConfigurationError;

class Feature
{
    public const CONFIG_SECTION = 'kubernetes';

    public const CONFIG_KEY = 'enabled';

    public const MODE_AUTO = 'auto';

    public const MODE_YES = 'yes';

    public const MODE_NO = 'no';

    public static function mode(): string
    {
        $config = Config::module('businessprocess');

        if (! $config->hasSection(self::CONFIG_SECTION)) {
            return self::MODE_AUTO;
        }

        $mode = strtolower((string) $config->getSection(self::CONFIG_SECTION)->get(self::CONFIG_KEY, self::MODE_AUTO));
        if (! in_array($mode, [self::MODE_AUTO, self::MODE_YES, self::MODE_NO], true)) {
            throw new ConfigurationError(
                'Invalid businessprocess Kubernetes mode "%s". Expected one of: auto, yes, no',
                $mode
            );
        }

        return $mode;
    }

    public static function isEnabled(): bool
    {
        $mode = self::mode();
        if ($mode === self::MODE_NO) {
            return false;
        }
        if ($mode === self::MODE_YES) {
            return true;
        }

        $section = Config::module('businessprocess')->getSection(self::CONFIG_SECTION);
        return getenv('ICINGA_KUBERNETES_API_ADMIN_TOKEN_FILE') !== false
            || (string) $section->get('api_token_file', '') !== '';
    }

    public static function isAvailable(): bool
    {
        try {
            return self::isEnabled();
        } catch (ConfigurationError $_) {
            return false;
        }
    }
}
