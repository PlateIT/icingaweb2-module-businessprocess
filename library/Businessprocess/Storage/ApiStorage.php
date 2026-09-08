<?php

// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\Storage;

use Icinga\Application\Hook\AuditHook;
use Icinga\Module\Businessprocess\BpConfig;
use Icinga\Module\Businessprocess\Kubernetes\ApiClient;
use Icinga\Module\Businessprocess\Kubernetes\ApiNotFoundException;

/**
 * PostgreSQL-backed storage through the central Kubernetes API.
 *
 * It never reads or writes local files.
 */
class ApiStorage extends Storage
{
    protected array $configs = [];
    protected array $definitions = [];
    protected ?array $processIndex = null;
    protected ?ApiClient $client = null;

    protected function client(): ApiClient
    {
        return $this->client ??= new ApiClient();
    }

    public function listProcesses()
    {
        $processes = [];
        foreach ($this->listAllProcessNames() as $name) {
            $metadata = $this->loadMetadata($name);
            if ($metadata->canRead()) {
                $processes[$name] = $metadata->getExtendedTitle();
            }
        }
        natcasesort($processes);
        return $processes;
    }

    public function listProcessNames()
    {
        $names = [];
        foreach ($this->listAllProcessNames() as $name) {
            if ($this->loadMetadata($name)->canRead()) {
                $names[$name] = $name;
            }
        }
        natcasesort($names);
        return $names;
    }

    public function listAllProcessNames()
    {
        $this->loadProcessIndex();
        return array_keys($this->processIndex);
    }

    public function hasProcess($name)
    {
        try {
            return $this->loadMetadata($name)->canRead();
        } catch (ApiNotFoundException $_) {
            return false;
        }
    }

    public function loadProcess($name)
    {
        if (! isset($this->configs[$name])) {
            $this->configs[$name] = DefinitionCodec::decode($name, $this->getDefinition($name));
        }
        return $this->configs[$name];
    }

    public function storeProcess(BpConfig $process)
    {
        $name = $process->getName();
        $definition = DefinitionCodec::encode($process);
        $this->loadProcessIndex();
        $expectedGeneration = (int) ($this->processIndex[$name]['generation'] ?? 0);
        $stored = $this->client()->put('business-processes/' . rawurlencode($name), [
            'definition' => $definition,
            'expectedGeneration' => $expectedGeneration
        ]);
        if (! isset($stored['generation'], $stored['updatedAt'])
            || ! is_int($stored['generation'])
            || $stored['generation'] < 1
            || ! is_string($stored['updatedAt'])
            || $stored['updatedAt'] === ''
        ) {
            throw new \RuntimeException('Business process API returned an invalid store result');
        }
        $this->definitions[$name] = $definition;
        $this->configs[$name] = $process;
        $this->processIndex[$name] = [
            'generation' => $stored['generation'],
            'updatedAt' => $stored['updatedAt']
        ];
        AuditHook::logActivity('businessprocess/store', "Business Process \"$name\" stored in PostgreSQL");
        return true;
    }

    public function deleteProcess($name)
    {
        $this->loadProcessIndex();
        if (! isset($this->processIndex[$name])) {
            return false;
        }
        try {
            $deleted = $this->client()->delete(
                'business-processes/' . rawurlencode($name),
                ['expectedGeneration' => $this->processIndex[$name]['generation']]
            );
        } catch (ApiNotFoundException $_) {
            return false;
        }
        unset($this->definitions[$name], $this->configs[$name]);
        if ($this->processIndex !== null) {
            unset($this->processIndex[$name]);
        }
        AuditHook::logActivity('businessprocess/delete', "Business Process \"$name\" deleted from PostgreSQL");
        return $deleted;
    }

    public function loadMetadata($name)
    {
        if (isset($this->configs[$name])) {
            return $this->configs[$name]->getMetadata();
        }
        return $this->loadProcess($name)->getMetadata();
    }

    public function getDefinition($name): array
    {
        if (! array_key_exists($name, $this->definitions)) {
            $response = $this->client()->get('business-processes/' . rawurlencode($name));
            $definition = $response['definition'] ?? null;
            if (! is_array($definition)) {
                throw new \RuntimeException('Business process API returned an invalid definition');
            }
            $this->definitions[$name] = $definition;
        }
        return $this->definitions[$name];
    }

    public function getVersionFingerprint(): string
    {
        $this->loadProcessIndex();
        return hash('sha256', json_encode(
            $this->processIndex,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        ));
    }

    protected function loadProcessIndex(): void
    {
        if ($this->processIndex !== null) {
            return;
        }
        $response = $this->client()->get('business-processes');
        if (! isset($response['items']) || ! is_array($response['items']) || ! array_is_list($response['items'])) {
            throw new \RuntimeException('Business process API returned an invalid index');
        }
        $this->processIndex = [];
        foreach ($response['items'] as $item) {
            if (! is_array($item)
                || ! is_string($item['name'] ?? null)
                || $item['name'] === ''
                || strlen($item['name']) > 255
                || ! preg_match('/^[A-Za-z0-9_.-]+$/D', $item['name'])
                || ! is_int($item['generation'] ?? null)
                || $item['generation'] < 1
                || ! is_string($item['updatedAt'] ?? null)
                || $item['updatedAt'] === ''
                || isset($this->processIndex[$item['name']])
            ) {
                throw new \RuntimeException('Business process API returned an invalid index');
            }
            $this->processIndex[$item['name']] = [
                'generation' => $item['generation'],
                'updatedAt' => $item['updatedAt']
            ];
        }
    }

    public function loadFromString($name, $string)
    {
        $definition = json_decode($string, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($definition)) {
            throw new \RuntimeException('Business process definition must be a JSON object');
        }
        return DefinitionCodec::decode($name, $definition);
    }
}
