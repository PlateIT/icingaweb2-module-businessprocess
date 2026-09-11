<?php

// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\Kubernetes;

use Throwable;

/** Shared choices and fields for the IPL create and Zend edit forms. */
final class SelectorForm
{
    public const FILTERS = ['cluster', 'group', 'version', 'kind', 'namespace', 'name', 'labels', 'ownerUID'];

    public static function choices(array $values): array
    {
        $result = ['clusters' => [], 'kinds' => [], 'namespaces' => [], 'available' => true];
        try {
            $result['clusters'] = ObjectRepository::clusters();
            $cluster = $values['cluster'] ?: array_key_first($result['clusters']);
            if ($cluster === null) {
                return $result;
            }
            foreach (ObjectRepository::resourceTypes($cluster)['items'] as $type) {
                $result['kinds'][$type['kind']] = $type['kind'];
            }
            natcasesort($result['kinds']);
            $result['namespaces'] = ObjectRepository::namespaces($cluster, [
                'group' => $values['group'], 'version' => $values['version'], 'kind' => $values['kind']
            ]);
        } catch (Throwable $_) {
            $result['available'] = false;
        }
        return $result;
    }

    public static function fields(array $values, array $choices, callable $translate, bool $ipl): array
    {
        $clusters = ['' => $translate('Default cluster')];
        foreach ($choices['clusters'] as $name => $source) {
            $clusters[$name] = $name;
        }
        $kinds = ['' => $translate('All object types')] + $choices['kinds'];
        $namespaces = ['' => $translate('All available namespaces')];
        foreach ($choices['namespaces'] as $namespace) {
            $namespaces[$namespace] = $namespace;
        }
        // An existing selector must never be narrowed or cleared just because
        // its objects are currently absent or the inventory is unavailable.
        $menus = ['cluster' => $clusters, 'kind' => $kinds, 'namespace' => $namespaces];
        foreach ($menus as $key => $options) {
            if ($values[$key] !== '' && ! isset($options[$values[$key]])) {
                $menus[$key][$values[$key]] = $values[$key] . ' (' . $translate('not in current inventory') . ')';
            }
        }
        $fields = [];
        foreach ($menus as $key => $options) {
            $label = ['cluster' => 'Cluster', 'kind' => 'Object type', 'namespace' => 'Namespace (optional)'][$key];
            $attributes = [
                'label' => $translate($label), 'multiOptions' => $options,
                'value' => $values[$key], 'required' => false
            ];
            if ($key !== 'namespace') {
                $attributes['class'] = 'autosubmit';
            }
            if (! $choices['available']) {
                $attributes['description'] = $translate('Inventory unavailable. Existing filters are retained.');
            }
            $fields['selector_' . $key] = ['select', $attributes];
        }
        $fields['selector_labels'] = ['text', [
            'data-selector-preview' => '1',
            'label' => $translate('Labels (optional)'), 'value' => $values['labels'],
            'placeholder' => 'app=payments,environment=test',
            'description' => $translate('Matching objects are included automatically, including objects created later.')
        ]];
        foreach (['name' => 'Exact object name', 'group' => 'API group', 'version' => 'API version', 'ownerUID' => 'Owner UID'] as $key => $label) {
            $fields['selector_' . $key] = ['text', [
                'label' => $translate($label), 'value' => $values[$key],
                'required' => false, 'data-selector-advanced' => '1'
            ]];
        }
        $fields['selector_states'] = [$ipl ? 'select' : 'multiselect', [
            'label' => $translate('Filter by current state'), 'multiple' => true,
            'multiOptions' => ['ok' => 'OK', 'warning' => 'WARNING', 'critical' => 'CRITICAL', 'unknown' => 'UNKNOWN'],
            'value' => $values['states'], 'data-selector-advanced' => '1',
            'description' => $translate('Leave empty to include healthy and unhealthy objects.')
        ]];
        $fields['selector_aggregation'] = ['select', [
            'label' => $translate('Combined status'), 'required' => true,
            'multiOptions' => ['worst' => $translate('Worst member state'), 'and' => 'AND', 'or' => 'OR'],
            'value' => $values['aggregation'], 'data-selector-advanced' => '1', 'data-default-value' => 'worst'
        ]];
        return $fields;
    }
}
