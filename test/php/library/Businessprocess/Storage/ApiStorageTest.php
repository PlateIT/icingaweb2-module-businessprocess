<?php

namespace Tests\Icinga\Module\Businessprocess\Storage;

use Icinga\Module\Businessprocess\Kubernetes\ApiClient;
use Icinga\Module\Businessprocess\Storage\ApiStorage;
use Icinga\Module\Businessprocess\Test\BaseTestCase;

class ApiStorageTest extends BaseTestCase
{
    public function testProcessCanBeLoadedFromStructuredApiDefinition(): void
    {
        $storage = new TestApiStorage($this->emptyConfigSection());
        $storage->inject(new FakeApiClient([
            'simple' => [
                'version' => 2,
                'metadata' => ['Title' => 'Simple'],
                'nodes' => [[
                    'type' => 'process',
                    'name' => 'top',
                    'operator' => '&',
                    'children' => ['host;service'],
                    'display' => 1
                ]],
                'roots' => ['top']
            ]
        ]));
        $this->assertSame(['simple'], $storage->listAllProcessNames());
        $this->assertTrue($storage->hasProcess('simple'));
        $this->assertSame('Simple', $storage->loadProcess('simple')->getMetadata()->get('Title'));
    }

    public function testJsonDefinitionCanBeParsedBeforeItIsStored(): void
    {
        $storage = new ApiStorage($this->emptyConfigSection());
        $definition = json_encode([
            'version' => 2,
            'metadata' => [],
            'nodes' => [[
                'type' => 'process',
                'name' => 'top',
                'operator' => '&',
                'children' => ['host;service']
            ]],
            'roots' => ['top']
        ], JSON_THROW_ON_ERROR);
        $this->assertTrue($storage->loadFromString('draft', $definition)->hasBpNode('top'));
    }

    public function testWritesAndDeletesCarryTheLoadedGeneration(): void
    {
        $storage = new TestApiStorage($this->emptyConfigSection());
        $client = new FakeApiClient([
            'simple' => [
                'version' => 2,
                'metadata' => ['Title' => 'Simple'],
                'nodes' => [],
                'roots' => []
            ]
        ]);
        $storage->inject($client);
        $process = $storage->loadProcess('simple');

        $storage->storeProcess($process);
        $this->assertSame(1, $client->lastExpectedGeneration);

        $storage->deleteProcess('simple');
        $this->assertSame(2, $client->lastExpectedGeneration);
    }
}

class TestApiStorage extends ApiStorage
{
    public function inject(ApiClient $client): void { $this->client = $client; }
}

class FakeApiClient extends ApiClient
{
    public ?int $lastExpectedGeneration = null;

    private array $generations = [];

    public function __construct(private array $definitions) {}
    public function get(string $path, array $query = []): array
    {
        if ($path === 'business-processes') {
            return ['items' => array_map(fn(string $name): array => [
                'name' => $name,
                'generation' => $this->generations[$name] ?? 1,
                'updatedAt' => '2026-09-01T00:00:00Z'
            ], array_keys($this->definitions))];
        }
        $name = rawurldecode(substr($path, strlen('business-processes/')));
        return ['name' => $name, 'definition' => $this->definitions[$name]];
    }

    public function put(string $path, array $body): array
    {
        $name = rawurldecode(substr($path, strlen('business-processes/')));
        $this->lastExpectedGeneration = $body['expectedGeneration'];
        $this->definitions[$name] = $body['definition'];
        $generation = $body['expectedGeneration'] + 1;
        $this->generations[$name] = $generation;
        return ['generation' => $generation, 'updatedAt' => '2026-09-01T00:00:00Z'];
    }

    public function delete(string $path, array $query = []): bool
    {
        $this->lastExpectedGeneration = $query['expectedGeneration'];
        $name = rawurldecode(substr($path, strlen('business-processes/')));
        unset($this->definitions[$name], $this->generations[$name]);
        return true;
    }
}
