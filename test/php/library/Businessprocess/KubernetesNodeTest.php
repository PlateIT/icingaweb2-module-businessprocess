<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Businessprocess;

use Icinga\Module\Businessprocess\BpConfig;
use Icinga\Module\Businessprocess\Kubernetes\Kind;
use Icinga\Module\Businessprocess\Kubernetes\NodeName;
use Icinga\Module\Businessprocess\Kubernetes\ObjectRepository;
use Icinga\Module\Businessprocess\KubernetesNode;
use Icinga\Module\Businessprocess\Storage\DefinitionCodec;
use Icinga\Module\Businessprocess\Test\BaseTestCase;
use ReflectionProperty;

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

    public function testBusinessProcessLabelDoesNotContainClusterName(): void
    {
        $parts = ObjectRepository::compactLabelParts('deployment', (object) [
            'cluster_uuid' => 'unused',
            'namespace' => 'eiam-portal-admin-01-p',
            'name' => 'rdm-api-application-primary'
        ]);

        $this->assertSame([
            'Deployment',
            'eiam-portal-admin-01-p',
            'rdm-api-application-primary'
        ], $parts);
    }

    public function testStructuredDefinitionRoundTripsKubernetesOptions(): void
    {
        $uuid = '2f4f2c04-1111-4222-9333-9d33e02c1234';
        $config = new BpConfig('kubernetes');
        $config->createKubernetesNode('namespace', $uuid)
            ->setExpandDependencies(true)
            ->setNamespaceInclude(['deployment', 'standalone_pod', 'secret'])
            ->setDisplay(1)
            ->setAlias('Kubernetes Namespace');
        $config->addRootNode('kubernetes:namespace:' . $uuid);
        $config = DefinitionCodec::decode('kubernetes', DefinitionCodec::encode($config));

        $node = $config->getNode('kubernetes:namespace:' . $uuid);

        $this->assertInstanceOf(KubernetesNode::class, $node);
        $this->assertTrue($node->expandsDependencies());
        $this->assertSame(['deployment', 'standalone_pod', 'secret'], $node->getNamespaceInclude());

        $this->assertSame('Kubernetes Namespace', $node->getStoredAlias());
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

    public function testUnavailableIsUnknownAndNotReportedAsDeletion(): void
    {
        $config = new BpConfig();
        $deployment = $config->createKubernetesNode(
            'deployment',
            '10000000-0000-4000-8000-000000000000'
        );
        $pod = $config->createKubernetesNode('pod', '20000000-0000-4000-8000-000000000000');
        $pod->setState(KubernetesNode::ICINGA_OK);
        $deployment->addChild($pod);

        $deployment->markUnavailable();

        $this->assertSame(KubernetesNode::ICINGA_UNKNOWN, $deployment->getState());
        $this->assertNull($deployment->getDeleteReason());
        $this->assertFalse($deployment->isMissing());
    }

    public function testStaleWorkloadCannotInheritGreenChildState(): void
    {
        $uuid = '10000000-0000-4000-8000-000000000000';
        $objects = new ReflectionProperty(ObjectRepository::class, 'objects');
        $objects->setAccessible(true);
        $previous = $objects->getValue();
        $objects->setValue(null, ['deployment:' . $uuid => (object) [
            'uuid' => $uuid,
            'cluster_uuid' => 'cluster-a',
            'cluster_name' => 'cluster-a',
            'namespace' => 'default',
            'name' => 'api',
            'icinga_state' => 'ok',
            'freshness' => 'stale'
        ]]);
        $previousTokenFile = getenv('ICINGA_KUBERNETES_API_ADMIN_TOKEN_FILE');
        putenv('ICINGA_KUBERNETES_API_ADMIN_TOKEN_FILE=test-token-file');

        try {
            $config = new BpConfig();
            $deployment = $config->createKubernetesNode('deployment', $uuid);
            $pod = $config->createKubernetesNode('pod', '20000000-0000-4000-8000-000000000000');
            $pod->setState(KubernetesNode::ICINGA_OK);
            $deployment->addChild($pod)->refreshFromKubernetes();

            $this->assertSame(KubernetesNode::ICINGA_UNKNOWN, $deployment->getState());
            $this->assertFalse($deployment->isMissing());
        } finally {
            $objects->setValue(null, $previous);
            putenv($previousTokenFile === false
                ? 'ICINGA_KUBERNETES_API_ADMIN_TOKEN_FILE'
                : 'ICINGA_KUBERNETES_API_ADMIN_TOKEN_FILE=' . $previousTokenFile);
        }
    }

    public function testImplicitKubernetesNodesAreNotPersisted(): void
    {
        $deploymentUuid = '2f4f2c04-1111-4222-9333-9d33e02c1234';
        $podUuid = '3f4f2c04-1111-4222-9333-9d33e02c1234';
        $config = new BpConfig('kubernetes');
        $config->createKubernetesNode('deployment', $deploymentUuid)
            ->setExpectedCluster('primus')
            ->setExpectedGroup('apps')
            ->setExpectedVersion('v1')
            ->setApiKind('Deployment')
            ->setExpandDependencies(true)
            ->setDisplay(1)
            ->setAlias('Deployment');
        $config->addRootNode('kubernetes:deployment:' . $deploymentUuid);

        $deployment = $config->getNode('kubernetes:deployment:' . $deploymentUuid);
        $pod = $config->createKubernetesNode('pod', $podUuid, false);
        $deployment->addChild($pod);

        $definition = DefinitionCodec::encode($config);
        $this->assertSame(['kubernetes:deployment:' . $deploymentUuid], array_column($definition['nodes'], 'name'));
        $this->assertSame('primus', $definition['nodes'][0]['cluster']);
        $this->assertSame('apps', $definition['nodes'][0]['group']);
        $this->assertSame('v1', $definition['nodes'][0]['version']);
        $this->assertSame('Deployment', $definition['nodes'][0]['apiKind']);
        $this->assertSame([], $definition['nodes'][0]['children']);
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

    public function testDeploymentIsOkWhenAtLeastOnePodIsOk(): void
    {
        $config = new BpConfig();
        $deployment = $config->createKubernetesNode('deployment', '10000000-0000-4000-8000-000000000000');
        $replicaSet = $config->createKubernetesNode('replicaset', '20000000-0000-4000-8000-000000000000');
        $okPod = $config->createKubernetesNode('pod', '30000000-0000-4000-8000-000000000000');
        $criticalPod = $config->createKubernetesNode('pod', '40000000-0000-4000-8000-000000000000');
        $okPod->setState(KubernetesNode::ICINGA_OK);
        $criticalPod->setState(KubernetesNode::ICINGA_CRITICAL);

        $replicaSet->addChild($okPod)->addChild($criticalPod);
        $deployment->addChild($replicaSet);

        $this->assertSame(KubernetesNode::OP_OR, $deployment->getOperator());
        $this->assertSame(KubernetesNode::OP_OR, $replicaSet->getOperator());
        $this->assertSame(KubernetesNode::ICINGA_OK, $deployment->getState());
    }

    public function testStatefulSetRequiresOneOfOneOrTwoPods(): void
    {
        $singleConfig = new BpConfig();
        $single = $singleConfig->createKubernetesNode('statefulset', '50000000-0000-4000-8000-000000000000');
        $singlePod = $singleConfig->createKubernetesNode('pod', '60000000-0000-4000-8000-000000000000');
        $singlePod->setState(KubernetesNode::ICINGA_OK);
        $single->addChild($singlePod);

        $this->assertSame(1, $single->getOperator());
        $this->assertSame(KubernetesNode::ICINGA_OK, $single->getState());

        $redundantConfig = new BpConfig();
        $redundant = $redundantConfig->createKubernetesNode(
            'statefulset',
            '70000000-0000-4000-8000-000000000000'
        );
        $okPod = $redundantConfig->createKubernetesNode('pod', '80000000-0000-4000-8000-000000000000');
        $criticalPod = $redundantConfig->createKubernetesNode('pod', '90000000-0000-4000-8000-000000000000');
        $okPod->setState(KubernetesNode::ICINGA_OK);
        $criticalPod->setState(KubernetesNode::ICINGA_CRITICAL);
        $redundant->addChild($okPod)->addChild($criticalPod);

        $this->assertSame(2, $redundant->getOperator());
        $this->assertSame(KubernetesNode::ICINGA_CRITICAL, $redundant->getState());

        $secondOkPod = $redundantConfig->createKubernetesNode('pod', 'a0000000-0000-4000-8000-000000000000');
        $secondOkPod->setState(KubernetesNode::ICINGA_OK);
        $redundant->addChild($secondOkPod);

        $this->assertSame(KubernetesNode::ICINGA_OK, $redundant->getState());
    }
}
