<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Run after bootstrapping Icinga Web with the businessprocess module enabled.

use Icinga\Module\Businessprocess\BpConfig;
use Icinga\Module\Businessprocess\Node;
use Icinga\Module\Businessprocess\Renderer\TreeRenderer;
use Icinga\Module\Businessprocess\Web\Url;

$config = new BpConfig('tree-actions');
\Icinga\Module\Businessprocess\Web\FakeRequest::setConfiguredBaseUrl('/icingaweb2');
$tree = new class($config) extends TreeRenderer {
    public function actionsFor(Node $node): string {
        $actions = $this->getActionIcons($this->getBusinessProcess(), $node);
        return is_array($actions) ? implode('', $actions) : (string) $actions;
    }
};
$tree->setUrl(Url::fromPath('businessprocess/process/show', ['config' => 'tree-actions']));
$explicit = $config->createKubernetesNode('deployment', '10000000-0000-4000-8000-000000000001');
$generated = $config->createKubernetesNode('pod', '10000000-0000-4000-8000-000000000002', false);
$classic = $config->createBp('classic');
foreach ([[$explicit, true, false], [$generated, false, false], [$classic, true, true]] as [$node, $editable, $addable]) {
    $html = $tree->actionsFor($node);
    if (str_contains($html, 'action=edit') !== $editable
        || str_contains($html, 'action=delete') !== $editable
        || str_contains($html, 'action=add') !== $addable) {
        throw new RuntimeException('Incorrect tree actions for ' . $node->getName());
    }
}
echo "Tree edit/delete/add actions respect configured and generated nodes OK\n";
