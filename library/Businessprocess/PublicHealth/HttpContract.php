<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\PublicHealth;

class HttpContract
{
    private const MAX_BODY_BYTES = 4 * 1024 * 1024;
    public const ALLOW = 'GET, HEAD';

    public static function allows(string $method): bool
    {
        return $method === 'GET' || $method === 'HEAD';
    }

    /** @return array<string, string> */
    public static function headers(): array
    {
        return [
            'Content-Type' => 'application/health+json; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff'
        ];
    }

    public static function encode(array $payload): string
    {
        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        if (strlen($encoded) > self::MAX_BODY_BYTES) {
            throw new \RuntimeException('Public Health response exceeds 4 MiB');
        }

        return $encoded;
    }

    public static function hasBody(string $method): bool
    {
        return $method !== 'HEAD';
    }
}
