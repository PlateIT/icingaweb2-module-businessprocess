<?php

namespace Tests\Icinga\Module\Businessprocess;

use Icinga\Module\Businessprocess\BpConfig;
use Icinga\Module\Businessprocess\KubernetesSelectorNode;
use Icinga\Module\Businessprocess\Storage\DefinitionCodec;
use PHPUnit\Framework\TestCase;

class KubernetesSelectorNodeTest extends TestCase
{
    public function testSelectorRoundTrip(): void
    {
        $config = new BpConfig('test');
        $config->createKubernetesSelectorNode('kubernetes-selector:payments')
            ->setSelector([
                'cluster' => 'campus',
                'group' => 'apps',
                'version' => 'v1',
                'kind' => 'Deployment',
                'namespace' => 'payments',
                'labels' => 'app=payments'
            ])
            ->setAggregation('and')
            ->setDisplay(1)
            ->setAlias('Payments');
        $config->addRootNode('kubernetes-selector:payments');
        $node = $config->getNode('kubernetes-selector:payments');
        $this->assertInstanceOf(KubernetesSelectorNode::class, $node);
        $this->assertSame('app=payments', $node->getSelector()['labels']);
        $this->assertSame('and', $node->getAggregation());

        $reloaded = DefinitionCodec::decode('test', DefinitionCodec::encode($config))
            ->getNode('kubernetes-selector:payments');
        $this->assertSame($node->getSelector(), $reloaded->getSelector());
        $this->assertSame('and', $reloaded->getAggregation());
    }

    public function testUnavailableSelectorIsExplicitlyUnknown(): void
    {
        $node = new KubernetesSelectorNode('kubernetes-selector:payments');

        $node->markUnavailable();

        $this->assertSame(KubernetesSelectorNode::ICINGA_UNKNOWN, $node->getState());
        $this->assertSame('unavailable', $node->getFreshness());
    }
}
