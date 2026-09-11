<?php
// SPDX-License-Identifier: GPL-3.0-or-later
require __DIR__ . '/standalone-definition-codec.php';
use Icinga\Module\Businessprocess\BpConfig;
use Icinga\Module\Businessprocess\Node;
use Icinga\Module\Businessprocess\Storage\DefinitionCodec;

$config = new BpConfig('selector-children');
$selector = $config->createKubernetesSelectorNode('selection')->setSelector(['labels' => 'app=icinga']);
$config->addRootNode('selection');
$before = DefinitionCodec::encode($config);
$items = [];
for ($i = 1; $i <= 6; ++$i) {
    $items[] = ['id' => sprintf('10000000-0000-4000-8000-%012d', $i), 'uid' => 'uid-' . $i,
        'cluster' => 'example', 'group' => '', 'version' => 'v1', 'kind' => $i <= 2 ? 'Pod' : 'PersistentVolumeClaim',
        'namespace' => 'example', 'name' => 'object-' . $i, 'resourceVersion' => '1',
        'state' => 'ok', 'observedAt' => '2026-09-08T20:00:00Z'];
}
$result = ['items' => $items, 'matchedCount' => 6, 'state' => 'ok', 'freshness' => 'live'];
$selector->applyResolution($result);
check(count($selector->getChildren()) === 6, 'Selector must materialize six matches');
check($selector->getState() === Node::ICINGA_OK, 'Root selector lost resolved state');
check(DefinitionCodec::encode($config) === $before, 'Generated matches must not be persisted');
$parent = $config->createBp('parent')->addChild($selector);
check($parent->getState() === Node::ICINGA_OK, 'Nested selector must evaluate without max([])');
$first = array_values($selector->getChildren())[0];
$selector->applyResolution($result);
check(count($first->getParents()) === 1, 'Refresh duplicated parent links');
$selector->applyResolution(['items' => [], 'matchedCount' => 0, 'state' => 'unknown', 'freshness' => 'live']);
$parent->clearState();
check(! $selector->hasChildren() && $first->getParents() === [], 'Empty selection retained matches or parent links');
check($parent->getState() === Node::ICINGA_UNKNOWN, 'Empty nested selector must be UNKNOWN');
$selector->reCalculateState();
$selector->applyResolution($result);
$selector->markUnavailable();
check(! $selector->hasChildren() && $selector->getState() === Node::ICINGA_UNKNOWN, 'Outage retained green matches');
$selector->applyResolution($result + []);
check(count($selector->getChildren()) === 6, 'Recovery did not restore matches');
$selector->applyResolution(['items' => [$items[0]], 'matchedCount' => 1001, 'state' => 'critical', 'freshness' => 'live']);
check($selector->getState() === Node::ICINGA_CRITICAL, 'Must retain server aggregate beyond displayed matches');
echo "Selector children, nested/empty state, refresh, outage and persistence OK\n";
