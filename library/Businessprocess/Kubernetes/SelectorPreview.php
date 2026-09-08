<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace Icinga\Module\Businessprocess\Kubernetes;

use InvalidArgumentException;

final class SelectorPreview
{
    public static function queries(array $input, array $roles): array
    {
        $filters = [];
        foreach (SelectorForm::FILTERS as $name) {
            $value = $input[$name] ?? '';
            if (! is_string($value) || strlen($value) > ($name === 'labels' ? 4096 : 512) || strpos($value, "\0") !== false) {
                throw new InvalidArgumentException('Invalid selector filter');
            }
            if (trim($value) !== '') $filters[$name] = trim($value);
        }
        $states = $input['states'] ?? [];
        if (! is_array($states) || count($states) > 4) {
            throw new InvalidArgumentException('Invalid state filter');
        }
        foreach ($states as $state) if (! is_string($state) || ! in_array($state, ['ok', 'warning', 'critical', 'unknown'], true)) {
            throw new InvalidArgumentException('Invalid state filter');
        }
        $queries = [];
        foreach ($roles ?: [[]] as $role) foreach ($states ?: [''] as $state) {
            $query = $filters;
            if ($state !== '') $query['state'] = $state;
            foreach ($role as $key => $value) {
                if (isset($query[$key]) && $query[$key] !== $value) continue 2;
                $query[$key] = $value;
            }
            $queries[] = ['path' => 'resources', 'query' => $query + ['limit' => 51, 'order' => 'id']];
        }
        if (count($queries) > 64) throw new InvalidArgumentException('Too many selector alternatives');
        return $queries;
    }
}
