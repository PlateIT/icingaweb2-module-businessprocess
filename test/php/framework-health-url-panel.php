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
$child = $config->createBp('child')->setAlias('Direct child')->setPublicStatus(true);
$grandchild = $config->createBp('grandchild')->setAlias('Grandchild')->setPublicStatus(true);
$config->getBpNode('public')->addChild($child);
$child->addChild($grandchild);
$panel = new class($config) extends HealthUrlPanel {
    protected function healthUrl(string $path): string
    {
        return '/icingaweb2/' . $path;
    }
};
$html = $panel->render();
if (substr_count($html, 'data-copy-health-url') !== 2
    || ! str_contains($html, '/icingaweb2/businessprocess/health/stable-example/published-service')
    || str_contains($html, 'not-published') || str_contains($html, 'direct-child')) {
    throw new RuntimeException('Health URL discovery must preserve the base path and expose only published node URLs');
}
$panelClass = get_class($panel);
$html = (new $panelClass($config, $config->getBpNode('public')))->render();
if (substr_count($html, 'data-copy-health-url') !== 3 || str_contains($html, 'grandchild')) {
    throw new RuntimeException('Root panel must show configuration, self and direct child only');
}
$html = (new $panelClass($config, $child))->render();
if (substr_count($html, 'data-copy-health-url') !== 3 || ! str_contains($html, 'grandchild')) {
    throw new RuntimeException('Child panel must show parent, self and direct child');
}
echo "Health URL panel and published node filtering OK\n";
