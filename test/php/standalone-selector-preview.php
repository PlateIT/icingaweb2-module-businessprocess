<?php
// SPDX-License-Identifier: GPL-3.0-or-later
require dirname(__DIR__, 2) . '/library/Businessprocess/Kubernetes/SelectorForm.php';
require dirname(__DIR__, 2) . '/library/Businessprocess/Kubernetes/SelectorPreview.php';
use Icinga\Module\Businessprocess\Kubernetes\SelectorPreview;

$queries = SelectorPreview::queries(['labels' => 'app=icinga', 'states' => ['warning','critical']], [['namespace' => 'allowed']]);
if (count($queries) !== 2) throw new RuntimeException('State alternatives missing');
foreach ($queries as $query) {
    if ($query['query']['namespace'] !== 'allowed' || $query['query']['labels'] !== 'app=icinga' || $query['query']['limit'] !== 51) {
        throw new RuntimeException('Preview did not retain bounded role constraints');
    }
}
if (SelectorPreview::queries(['namespace' => 'private'], [['namespace' => 'allowed']]) !== []) {
    throw new RuntimeException('Conflicting role scope was widened');
}
foreach ([['states'=>[['warning']]], ['labels'=>['app=icinga']], ['name'=>str_repeat('x',513)]] as $invalid) {
    try { SelectorPreview::queries($invalid, []); throw new RuntimeException('Invalid input accepted'); }
    catch (InvalidArgumentException $_) {}
}
echo "Selector preview preserves scope and validates input OK\n";
