<?php

// SPDX-License-Identifier: GPL-3.0-or-later

require dirname(__DIR__, 2) . '/library/Businessprocess/Kubernetes/SelectorForm.php';

use Icinga\Module\Businessprocess\Kubernetes\SelectorForm;

$values = array_fill_keys(SelectorForm::FILTERS, '');
$values += ['states' => [], 'aggregation' => 'worst'];
$choices = ['clusters' => ['example' => 'local'], 'kinds' => ['Deployment' => 'Deployment'],
    'namespaces' => ['payments'], 'available' => true];
$translate = static fn($text) => $text;
$fields = SelectorForm::fields($values, $choices, $translate, true);
foreach (['cluster', 'kind', 'namespace'] as $field) {
    if ($fields['selector_' . $field][0] !== 'select') {
        throw new RuntimeException('Expected inventory dropdown: ' . $field);
    }
}
foreach (['group', 'version', 'ownerUID', 'name', 'states', 'aggregation'] as $field) {
    if (($fields['selector_' . $field][1]['data-selector-advanced'] ?? null) !== '1') {
        throw new RuntimeException('Technical field must be advanced: ' . $field);
    }
}
foreach ([true, false] as $ipl) {
    $existing = $values;
    $existing['cluster'] = 'temporarily-offline';
    $existing['kind'] = 'CustomWorkload';
    $existing['namespace'] = 'temporarily-empty';
    $existing['group'] = 'example.io';
    $existing['version'] = 'v2';
    $existing['states'] = ['warning'];
    $existing['aggregation'] = 'or';
    $fields = SelectorForm::fields($existing, $choices, $translate, $ipl);
    foreach (['cluster', 'kind', 'namespace'] as $field) {
        if (! isset($fields['selector_' . $field][1]['multiOptions'][$existing[$field]])) {
            throw new RuntimeException('Existing selection disappeared');
        }
    }
    foreach ($existing as $field => $value) {
        if ($fields['selector_' . $field][1]['value'] !== $value) {
            throw new RuntimeException('Existing filter changed: ' . $field);
        }
    }
    if ($fields['selector_states'][0] !== ($ipl ? 'select' : 'multiselect')) {
        throw new RuntimeException('Wrong framework multiple-select element');
    }
}
echo "Simple selector choices and preserved advanced filters OK\n";
