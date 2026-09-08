<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Run after bootstrapping Icinga Web and enabling the businessprocess module.

use Icinga\Module\Businessprocess\BpConfig;
use Icinga\Module\Businessprocess\Web\Component\HealthUrlPanel;

$config = new BpConfig('example');
$config->getMetadata()->set('PublicApiPath', 'stable-example');
$config->getMetadata()->set('PublicApiScope', 'published');
$config->createBp('private')->setAlias('Not published');
$config->createBp('public')->setAlias('Published service')->setPublicStatus(true)->setDisplay(1);
$config->addRootNode('public');
$panel = new class($config) extends HealthUrlPanel {
    protected function healthUrl(string $path): string
    {
        return '/icingaweb2/' . $path;
    }
};
$html = $panel->render();
if (substr_count($html, 'data-copy-health-url') !== 2
    || ! str_contains($html, '/icingaweb2/businessprocess/health/stable-example/published-service')
    || str_contains($html, 'not-published')) {
    throw new RuntimeException('Health URL discovery must preserve the base path and expose only published node URLs');
}
echo "Health URL panel and published node filtering OK\n";
