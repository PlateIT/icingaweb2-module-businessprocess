<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Businessprocess;

use Icinga\Module\Businessprocess\BpConfig;
use Icinga\Module\Businessprocess\Metadata;
use Icinga\Module\Businessprocess\Node;
use Icinga\Module\Businessprocess\PublicHealth\PublicHealthService;
use Icinga\Module\Businessprocess\PublicHealth\StatusMapper;
use Icinga\Module\Businessprocess\Storage\DefinitionCodec;
use Icinga\Module\Businessprocess\Storage\Storage;
use Icinga\Module\Businessprocess\Test\BaseTestCase;
use RuntimeException;

class PublicHealthTest extends BaseTestCase
{
    public function testStatusMappingAndAggregation(): void
    {
        $this->assertSame(StatusMapper::UP, StatusMapper::fromState(Node::ICINGA_OK));
        $this->assertSame(StatusMapper::DEGRADED, StatusMapper::fromState(Node::ICINGA_WARNING));
        $this->assertSame(StatusMapper::DOWN, StatusMapper::fromState(Node::ICINGA_CRITICAL));
        $this->assertSame(StatusMapper::UNKNOWN, StatusMapper::fromState(Node::ICINGA_UNKNOWN));
        $this->assertSame(StatusMapper::UNKNOWN, StatusMapper::fromState(Node::ICINGA_PENDING));
        $this->assertSame(StatusMapper::UNKNOWN, StatusMapper::aggregate([]));
        $this->assertSame(200, StatusMapper::httpStatus(StatusMapper::UP));
        $this->assertSame(200, StatusMapper::httpStatus(StatusMapper::DEGRADED));
        $this->assertSame(503, StatusMapper::httpStatus(StatusMapper::DOWN));
        $this->assertSame(503, StatusMapper::httpStatus(StatusMapper::UNKNOWN));
        $this->assertSame(
            StatusMapper::DOWN,
            StatusMapper::aggregate([StatusMapper::UP, StatusMapper::DOWN, StatusMapper::DEGRADED])
        );
    }

    public function testPublicSettingsRoundTrip(): void
    {
        $config = new BpConfig('service');
        $config->getMetadata()->set('Title', 'Public Service');
        $config->getMetadata()->set('PublicApiPath', 'public-service');
        $config->getMetadata()->set('PublicApi', 'yes');
        $config->getMetadata()->set('PublicApiScope', 'roots');
        $config->getMetadata()->set('PublicApiRelations', 'links');
        $config->createBp('root')->setChildNames(['localhost'])->setPublicStatus(true)
            ->setDisplay(1)->setAlias('Production');
        $config->addRootNode('root');
        $definition = DefinitionCodec::encode($config);
        $config = DefinitionCodec::decode('service', $definition);

        $this->assertTrue($config->getMetadata()->isPublicApiEnabled());
        $this->assertSame('public-service', PublicHealthService::pathForConfig($config));
        $this->assertSame('roots', $config->getMetadata()->getPublicApiScope());
        $this->assertSame('links', $config->getMetadata()->getPublicApiRelations());
        $this->assertTrue($config->getBpNode('root')->getPublicStatus());

        $this->assertSame(2, $definition['version']);
        $this->assertTrue($definition['nodes'][0]['publicStatus']);
    }

    public function testActuatorCatalogPublishesOnlyExplicitlyEnabledProcessNodes(): void
    {
        $config = $this->publicConfig();
        $storage = new PublicHealthStorage(['internal' => $config]);
        $service = new PublicHealthService($storage, static function (): void {
        });

        $catalog = $service->catalog();
        $this->assertSame(StatusMapper::DEGRADED, $catalog['status']);
        $this->assertSame(['public-services'], array_keys($catalog['components']));
        $components = $catalog['components']['public-services']['components'];
        $this->assertSame(['eiam-produktion', 'eiam-produktion/idp'], array_keys($components));
        $this->assertSame(
            'public-services/eiam-produktion',
            $components['eiam-produktion']['details']['path']
        );
        $this->assertArrayHasKey('children', $components['eiam-produktion']['details']['links']);

        $discovery = $service->discovery();
        $this->assertSame(StatusMapper::UP, $discovery['status']);
        $this->assertSame(
            '/businessprocess/health',
            $discovery['components']['businessprocess']['details']['href']
        );
        $this->assertSame(
            'public-services/eiam-produktion/idp',
            $service->node('public-services/eiam-produktion/idp')['details']['path']
        );
    }

    public function testPrivateAndInvalidResourcesAreNotAddressable(): void
    {
        $config = $this->publicConfig();
        $config->getBpNode('root')->setPublicStatus(false);
        $service = new PublicHealthService(
            new PublicHealthStorage(['internal' => $config]),
            static function (): void {
            }
        );

        $this->assertNull($service->node('public-services/eiam-produktion'));
        $this->assertNull($service->node('private/eiam-produktion'));
        $this->assertSame('foederations-komponente', PublicHealthService::slug('Föderations Komponente'));
    }

    public function testPublishedPayloadDoesNotExposeInternalIdentifiersOrSelectors(): void
    {
        $service = new PublicHealthService(
            new PublicHealthStorage(['internal-secret-name' => $this->publicConfig()]),
            static function (): void {
            }
        );
        $encoded = json_encode($service->catalog(), JSON_THROW_ON_ERROR);

        foreach (['internal-secret-name', 'uuid', 'selector', 'restriction', 'hostname'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    public function testConfigurationPathCollisionsFailClosed(): void
    {
        $first = $this->publicConfig();
        $second = $this->publicConfig();
        $second->getMetadata()->set('Title', $first->getMetadata()->getTitle());
        $service = new PublicHealthService(new PublicHealthStorage(['first' => $first, 'second' => $second]));

        $this->expectException(RuntimeException::class);
        $service->catalog();
    }

    public function testNodePathCollisionsFailClosed(): void
    {
        $config = $this->publicConfig();
        $duplicate = $config->createBp('duplicate')->setAlias('IDP')->setPublicStatus(true);
        $config->getBpNode('root')->addChild($duplicate);
        $service = new PublicHealthService(new PublicHealthStorage(['internal' => $config]));

        $this->expectException(RuntimeException::class);
        $service->catalog();
    }

    public function testNodePathValidationUsesTheSameRuntimeContract(): void
    {
        $config = $this->publicConfig();
        $duplicate = $config->createBp('duplicate')->setAlias('IDP')->setPublicStatus(true);
        $config->getBpNode('root')->addChild($duplicate);

        $this->expectException(RuntimeException::class);
        PublicHealthService::assertValidNodePaths($config, 'published');
    }

    public function testRootScopeRejectsPublishedNestedNodesBeforeStorage(): void
    {
        $this->expectException(RuntimeException::class);
        PublicHealthService::assertValidNodePaths($this->publicConfig(), 'roots');
    }

    public function testEveryRequestReloadsCurrentState(): void
    {
        $config = $this->publicConfig();
        $calls = 0;
        $service = new PublicHealthService(
            new PublicHealthStorage(['internal' => $config]),
            static function (BpConfig $loaded) use (&$calls): void {
                ++$calls;
                $loaded->getBpNode('root')->setState($calls === 1 ? Node::ICINGA_OK : Node::ICINGA_CRITICAL);
                $loaded->getBpNode('idp')->setState(Node::ICINGA_OK);
            }
        );

        $this->assertSame(StatusMapper::UP, $service->catalog()['status']);
        $this->assertSame(StatusMapper::DOWN, $service->catalog()['status']);
        $this->assertSame(2, $calls);
    }

    public function testCatalogDoesNotCacheStoredDefinitions(): void
    {
        $storage = new PublicHealthStorage(['internal' => $this->publicConfig()]);
        $service = new PublicHealthService($storage, static function (): void {
        });
        $this->assertSame(['public-services'], array_keys($service->catalog()['components']));

        $replacement = $this->publicConfig();
        $replacement->getMetadata()->set('Title', 'Renamed Services');
        $storage->storeProcess($replacement);

        $this->assertSame(['renamed-services'], array_keys($service->catalog()['components']));
    }

    public function testGeneratedAndRequestedPathsAreBoundedAndCanonical(): void
    {
        $longName = str_repeat('Long Service ', 20);
        $slug = PublicHealthService::slug($longName);
        $this->assertLessThanOrEqual(63, strlen($slug));
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug);
        $this->assertSame($slug, PublicHealthService::slug($longName));

        $service = new PublicHealthService(
            new PublicHealthStorage(['internal' => $this->publicConfig()]),
            static function (): void {
            }
        );
        $this->assertNull($service->config('../public-services'));
        $this->assertNull($service->config('PUBLIC-SERVICES'));
        $this->assertNull($service->node('public-services'));
        $this->assertNull($service->node('public-services//idp'));
        $this->assertNull($service->node(str_repeat('a', 2049) . '/node'));
    }

    protected function publicConfig(): BpConfig
    {
        $config = new BpConfig('internal');
        $metadata = $config->getMetadata();
        $metadata->set('Title', 'Public Services');
        $metadata->set('PublicApi', 'yes');
        $metadata->set('PublicApiScope', 'published');
        $metadata->set('PublicApiRelations', 'links');

        $root = $config->createBp('root')
            ->setAlias('eIAM PRODUKTION')
            ->setPublicStatus(true);
        $child = $config->createBp('idp')
            ->setAlias('IDP')
            ->setPublicStatus(true);
        $private = $config->createBp('private')->setAlias('Private');
        $root->addChild($child)->addChild($private);
        $root->setState(Node::ICINGA_WARNING);
        $child->setState(Node::ICINGA_OK);
        $private->setState(Node::ICINGA_OK);
        $config->addRootNode('root');

        return $config;
    }
}

class PublicHealthStorage extends Storage
{
    /** @var array<string, BpConfig> */
    protected array $configs;

    public function __construct(array $configs)
    {
        $this->configs = $configs;
    }

    public function listProcesses()
    {
        return array_keys($this->configs);
    }

    public function listProcessNames()
    {
        return array_keys($this->configs);
    }

    public function listAllProcessNames()
    {
        return array_keys($this->configs);
    }

    public function hasProcess($name)
    {
        return isset($this->configs[$name]);
    }

    public function loadProcess($name)
    {
        return $this->configs[$name];
    }

    public function storeProcess(BpConfig $config)
    {
        $this->configs[$config->getName()] = $config;
    }

    public function deleteProcess($name)
    {
        unset($this->configs[$name]);
        return true;
    }

    public function loadMetadata($name)
    {
        return $this->configs[$name]->getMetadata();
    }
}
