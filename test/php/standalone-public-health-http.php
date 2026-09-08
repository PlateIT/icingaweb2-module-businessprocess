<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

require dirname(__DIR__, 2) . '/library/Businessprocess/PublicHealth/HttpContract.php';

use Icinga\Module\Businessprocess\PublicHealth\HttpContract;

function assertTrue(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

assertTrue(HttpContract::allows('GET'), 'GET must be allowed');
assertTrue(HttpContract::allows('HEAD'), 'HEAD must be allowed');
assertTrue(! HttpContract::allows('POST'), 'POST must be rejected');
assertTrue(HttpContract::ALLOW === 'GET, HEAD', 'Allow contract changed');
assertTrue(HttpContract::headers() === [
    'Content-Type' => 'application/health+json; charset=utf-8',
    'Cache-Control' => 'no-store',
    'X-Content-Type-Options' => 'nosniff'
], 'security/cache headers changed');
assertTrue(HttpContract::hasBody('GET'), 'GET must return a body');
assertTrue(! HttpContract::hasBody('HEAD'), 'HEAD must not return a body');
assertTrue(
    HttpContract::encode(['status' => 'UNKNOWN', 'details' => ['href' => '/businessprocess/health']])
        === '{"status":"UNKNOWN","details":{"href":"/businessprocess/health"}}',
    'health JSON encoding changed'
);
try {
    HttpContract::encode(['status' => 'UP', 'details' => ['value' => str_repeat('x', 4 * 1024 * 1024)]]);
    throw new RuntimeException('Oversized public health response was accepted');
} catch (RuntimeException $error) {
    assertTrue($error->getMessage() !== 'Oversized public health response was accepted', $error->getMessage());
}

echo "Public Health HTTP contract tests passed.\n";
