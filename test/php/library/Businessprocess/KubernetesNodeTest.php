<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Businessprocess;

use Icinga\Module\Businessprocess\Kubernetes\Kind;
use Icinga\Module\Businessprocess\Kubernetes\NodeName;
use Icinga\Module\Businessprocess\KubernetesNode;
use Icinga\Module\Businessprocess\Storage\LegacyConfigParser;
use Icinga\Module\Businessprocess\Storage\LegacyConfigRenderer;
use Icinga\Module\Businessprocess\Test\BaseTestCase;

class KubernetesNodeTest extends BaseTestCase
{
    public function testNodeNameRoundTrip(): void
    {
        $uuid = '2f4f2c04-1111-4222-9333-9d33e02c1234';
        $name = NodeName::create('replica_set', $uuid);

        $this->assertSame('kubernetes:replicaset:' . $uuid, $name);
        $this->assertSame(['replicaset', $uuid], NodeName::parse($name));
    }

    public function testStateMapping(): void
    {
        $this->assertSame(KubernetesNode::ICINGA_OK, KubernetesNode::mapKubernetesState('ok'));
        $this->assertSame(KubernetesNode::ICINGA_WARNING, KubernetesNode::mapKubernetesState('warning'));
        $this->assertSame(KubernetesNode::ICINGA_CRITICAL, KubernetesNode::mapKubernetesState('critical'));
        $this->assertSame(KubernetesNode::ICINGA_UNKNOWN, KubernetesNode::mapKubernetesState('unknown'));
        $this->assertSame(KubernetesNode::ICINGA_PENDING, KubernetesNode::mapKubernetesState('pending'));
    }

    public function testLegacyConfigRoundTripsKubernetesOptions(): void
    {
        $uuid = '2f4f2c04-1111-4222-9333-9d33e02c1234';
        $config = LegacyConfigParser::parseString('kubernetes', sprintf(
            "kubernetes:namespace:%s =\n" .
            "k8s_options kubernetes:namespace:%s;expand_dependencies=1;namespace_include=deployment,standalone_pod,secret\n" .
            "display 1;kubernetes:namespace:%s;Kubernetes Namespace\n",
            $uuid,
            $uuid,
            $uuid
        ));

        $node = $config->getNode('kubernetes:namespace:' . $uuid);

        $this->assertInstanceOf(KubernetesNode::class, $node);
        $this->assertTrue($node->expandsDependencies());
        $this->assertSame(['deployment', 'standalone_pod', 'secret'], $node->getNamespaceInclude());

        $rendered = LegacyConfigRenderer::renderConfig($config);
        $this->assertStringContainsString('k8s_options kubernetes:namespace:' . $uuid, $rendered);
        $this->assertStringContainsString('namespace_include=deployment,standalone_pod,secret', $rendered);
    }


    public function testDeletedReasonIsVisibleButNotPersistedAsAlias(): void
    {
        $uuid = '2f4f2c04-1111-4222-9333-9d33e02c1234';
        $node = new KubernetesNode((object) [
            'kind' => 'deployment',
            'uuid' => $uuid
        ]);
        $node->setAlias('Deployment cluster / default / api');
        $node->markDeleted('Object no longer exists');

        $this->assertSame('Object no longer exists', $node->getDeleteReason());
        $this->assertStringContainsString('Object no longer exists', $node->getAlias());
        $this->assertSame('Deployment cluster / default / api', $node->getStoredAlias());
    }

    public function testImplicitKubernetesNodesAreNotPersisted(): void
    {
        $deploymentUuid = '2f4f2c04-1111-4222-9333-9d33e02c1234';
        $podUuid = '3f4f2c04-1111-4222-9333-9d33e02c1234';
        $config = LegacyConfigParser::parseString('kubernetes', sprintf(
            "kubernetes:deployment:%s =\n" .
            "k8s_options kubernetes:deployment:%s;expand_dependencies=1\n" .
            "display 1;kubernetes:deployment:%s;Deployment\n",
            $deploymentUuid,
            $deploymentUuid,
            $deploymentUuid
        ));

        $deployment = $config->getNode('kubernetes:deployment:' . $deploymentUuid);
        $pod = $config->createKubernetesNode('pod', $podUuid, false);
        $deployment->addChild($pod);

        $rendered = LegacyConfigRenderer::renderConfig($config);

        $this->assertStringContainsString('kubernetes:deployment:' . $deploymentUuid, $rendered);
        $this->assertStringNotContainsString('kubernetes:pod:' . $podUuid . ' =', $rendered);
        $this->assertStringNotContainsString('k8s_options kubernetes:pod:' . $podUuid, $rendered);
    }
    public function testDefaultNamespaceIncludeOptionsMatchSpecification(): void
    {
        $this->assertSame([
            'deployment',
            'statefulset',
            'daemonset',
            'cronjob',
            'standalone_job',
            'standalone_replicaset',
            'standalone_pod'
        ], Kind::DEFAULT_NAMESPACE_INCLUDE);
    }
}
