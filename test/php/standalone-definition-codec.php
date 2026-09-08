<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'Icinga\\Module\\Businessprocess\\';
    if (str_starts_with($class, $prefix)) {
        $path = $root . '/library/Businessprocess/'
            . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

use Icinga\Module\Businessprocess\BpConfig;
use Icinga\Module\Businessprocess\KubernetesNode;
use Icinga\Module\Businessprocess\KubernetesSelectorNode;
use Icinga\Module\Businessprocess\Node;
use Icinga\Module\Businessprocess\PublicHealth\PublicHealthService;
use Icinga\Module\Businessprocess\PublicHealth\StatusMapper;
use Icinga\Module\Businessprocess\Storage\DefinitionCodec;
use Icinga\Module\Businessprocess\Storage\Storage;

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$config = new BpConfig('example');
$metadata = $config->getMetadata();
$metadata->set('Title', 'Example Service');
$metadata->set('PublicApiPath', 'example-service');
$metadata->set('PublicApi', 'yes');
$metadata->set('PublicApiScope', 'published');
$metadata->set('PublicApiRelations', 'links');

$child = $config->createBp('database')
    ->setAlias('Database');
$rootNode = $config->createBp('service')
    ->setAlias('Service')
    ->setChildNames([$child->getName()])
    ->setDisplay(1)
    ->setPublicStatus(true)
    ->setStateOverrides([1 => 2], $child->getName());
$config->addRootNode($rootNode->getName());

$definition = DefinitionCodec::encode($config);
$decoded = DefinitionCodec::decode('example', $definition);
$roundTrip = DefinitionCodec::encode($decoded);

check($definition === $roundTrip, 'Structured definition roundtrip changed data');
check($decoded->getMetadata()->isPublicApiEnabled(), 'Public API metadata was lost');
check($decoded->getBpNode('service')->getPublicStatus(), 'Published component flag was lost');
check($decoded->getBpNode('service')->getChildNames() === ['database'], 'Hierarchy was lost');
check($decoded->listRootNodes() === ['service'], 'Root selection was lost');
check(
    $decoded->getBpNode('service')->getStateOverrides('database') === [1 => 2],
    'State overrides were lost'
);

$invalid = $definition;
$invalid['nodes'][] = $invalid['nodes'][0];
try {
    DefinitionCodec::decode('invalid', $invalid);
    throw new RuntimeException('Duplicate node was accepted');
} catch (RuntimeException $error) {
    check($error->getMessage() !== 'Duplicate node was accepted', $error->getMessage());
}

foreach ([
    $definition + ['legacy' => true],
    array_replace_recursive($definition, ['nodes' => [array_merge($definition['nodes'][0], ['source' => 'legacy'])]]),
    array_replace_recursive($definition, ['nodes' => [array_merge($definition['nodes'][0], ['publicStatus' => 'yes'])]])
] as $malformed) {
    try {
        DefinitionCodec::decode('invalid', $malformed);
        throw new RuntimeException('Malformed structured definition was accepted');
    } catch (RuntimeException $error) {
        check($error->getMessage() !== 'Malformed structured definition was accepted', $error->getMessage());
    }
}

$reordered = [
    'roots' => $definition['roots'],
    'nodes' => $definition['nodes'],
    'metadata' => $definition['metadata'],
    'version' => $definition['version']
];
check(
    DefinitionCodec::encode(DefinitionCodec::decode('reordered', $reordered)) === $definition,
    'Semantically irrelevant JSON object order was rejected'
);

echo "Definition codec smoke test OK\n";

$fixedConfig = new BpConfig('fixed-kubernetes');
$fixed = $fixedConfig->createKubernetesNode('database', '30000000-0000-4000-8000-000000000000')
    ->setExpectedCluster('campus')
    ->setExpectedGroup('example.io')
    ->setExpectedVersion('v1alpha1')
    ->setApiKind('Database')
    ->setDisplay(1);
$fixedConfig->addRootNode($fixed->getName());
$fixedDefinition = DefinitionCodec::encode($fixedConfig);
check(($fixedDefinition['nodes'][0]['cluster'] ?? null) === 'campus', 'Fixed node cluster was not persisted');
check(($fixedDefinition['nodes'][0]['group'] ?? null) === 'example.io', 'Fixed node group was not persisted');
check(($fixedDefinition['nodes'][0]['version'] ?? null) === 'v1alpha1', 'Fixed node version was not persisted');
check(($fixedDefinition['nodes'][0]['apiKind'] ?? null) === 'Database', 'Fixed node API kind was not persisted');
$fixedRoundTrip = DefinitionCodec::decode('fixed-kubernetes', $fixedDefinition);
check(
    DefinitionCodec::encode($fixedRoundTrip) === $fixedDefinition,
    'Cluster-bound operator CRD node did not survive a definition round trip'
);

$outageConfig = new BpConfig('outage');
$deployment = $outageConfig->createKubernetesNode(
    'deployment',
    '10000000-0000-4000-8000-000000000000'
);
$pod = $outageConfig->createKubernetesNode('pod', '20000000-0000-4000-8000-000000000000');
$pod->setState(Node::ICINGA_OK);
$deployment->addChild($pod)->markUnavailable();
check($deployment->getState() === Node::ICINGA_UNKNOWN, 'Unavailable dependency node retained a stale child state');
check(! $deployment->isMissing(), 'Unavailable Kubernetes node was falsely marked as deleted');
check($deployment->getDeleteReason() === null, 'Backend outage leaked as an object deletion reason');

$selector = new KubernetesSelectorNode('kubernetes-selector:outage');
$selector->markUnavailable();
check($selector->getState() === Node::ICINGA_UNKNOWN, 'Unavailable selector is not UNKNOWN');
check($selector->getFreshness() === 'unavailable', 'Unavailable selector freshness was lost');
foreach ([
    static fn() => $selector->setSelector(['unsupported' => 'value']),
    static fn() => $selector->setAggregation('invented')
] as $invalidSelectorMutation) {
    try {
        $invalidSelectorMutation();
        throw new RuntimeException('Invalid selector mutation was accepted');
    } catch (InvalidArgumentException $error) {
        // Expected fail-closed validation.
    }
}

echo "Kubernetes outage semantics smoke test OK\n";

$rootNode->setState(Node::ICINGA_WARNING);
$child->setPublicStatus(true)->setState(Node::ICINGA_OK);
$storage = new class (['example' => $config]) extends Storage {
    public function __construct(private array $items) {}
    public function listProcesses() { return array_keys($this->items); }
    public function listProcessNames() { return array_keys($this->items); }
    public function listAllProcessNames() { return array_keys($this->items); }
    public function hasProcess($name) { return isset($this->items[$name]); }
    public function loadProcess($name) { return $this->items[$name]; }
    public function storeProcess(BpConfig $config) { $this->items[$config->getName()] = $config; }
    public function deleteProcess($name) { unset($this->items[$name]); return true; }
    public function loadMetadata($name) { return $this->items[$name]->getMetadata(); }
};
$health = new PublicHealthService($storage, static function (): void {});
$catalog = $health->catalog();
check($catalog['status'] === StatusMapper::DEGRADED, 'Health aggregate is incorrect');
check(StatusMapper::httpStatus(StatusMapper::DEGRADED) === 200, 'DEGRADED must stay probe-readable');
check(StatusMapper::httpStatus(StatusMapper::DOWN) === 503, 'DOWN must fail the health probe');
check(array_keys($catalog) === ['status', 'components'], 'Health catalog is not Actuator-shaped');
check(isset($catalog['components']['example-service']), 'Generated configuration component is missing');
$healthConfig = $catalog['components']['example-service'];
check(isset($healthConfig['components']['service']), 'Published root component is missing');
check(isset($healthConfig['components']['service/database']), 'Published nested component is missing');
check(! isset($healthConfig['services'], $healthConfig['id']), 'Legacy public health fields leaked');
check(
    $health->node('example-service/service')['details']['path'] === 'example-service/service',
    'Generated component path is not addressable'
);
check($health->config('../example-service') === null, 'Non-canonical configuration path was accepted');
check($health->node('example-service//database') === null, 'Non-canonical node path was accepted');
$longSlug = PublicHealthService::slug(str_repeat('Long Service ', 20));
check(strlen($longSlug) <= 63, 'Generated public path segment is unbounded');
check((bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $longSlug), 'Generated public path is not canonical');

$config->getMetadata()->set('Title', 'Renamed Example Service');
check(
    $health->catalog()['components']['example-service']['details']['name'] === 'Renamed Example Service',
    'Title changes must update public labels without changing the stored URL'
);
$config->getMetadata()->set('Title', 'Example Service');

$stateLoads = 0;
$liveHealth = new PublicHealthService($storage, static function (BpConfig $loaded) use (&$stateLoads): void {
    ++$stateLoads;
    $loaded->getBpNode('service')->setState($stateLoads === 1 ? Node::ICINGA_OK : Node::ICINGA_CRITICAL);
    $loaded->getBpNode('database')->setState(Node::ICINGA_OK);
});
check($liveHealth->catalog()['status'] === StatusMapper::UP, 'First live health state is incorrect');
check($liveHealth->catalog()['status'] === StatusMapper::DOWN, 'Health state was cached across requests');
check($stateLoads === 2, 'Health state loader was not called for every request');

echo "Public health smoke test OK\n";

$newConfig = new BpConfig('NEW_SERVICE');
$newConfig->getMetadata()->set('Title', 'A different title');
$newDefinition = DefinitionCodec::encode($newConfig);
check($newDefinition['metadata']['PublicApiPath'] === 'new-service', 'New path must default to the ID');
unset($newDefinition['metadata']['PublicApiPath']);
$legacy = DefinitionCodec::decode('NEW_SERVICE', $newDefinition);
check(PublicHealthService::pathForConfig($legacy) === 'a-different-title', 'Existing generated URL must be preserved');
$legacy->getMetadata()->set('Title', 'Renamed');
check(DefinitionCodec::encode($legacy)['metadata']['PublicApiPath'] === 'a-different-title', 'Existing path must be pinned on save');
foreach (['Uppercase', '../private', 'two/segments', 'a--b', str_repeat('a', 64)] as $badPath) {
    check(! PublicHealthService::isValidConfigPath($badPath), 'Invalid path accepted: ' . $badPath);
}
$rootNode->setPublicStatus(false)->setAlias('private-parent-name');
$payload = json_encode($health->catalog(), JSON_THROW_ON_ERROR);
check(! str_contains($payload, 'private-parent-name'), 'Private ancestor name leaked into public path');
check($health->node('example-service/database') !== null, 'Public child must remain directly addressable');
$config->getMetadata()->set('PublicApiScope', 'roots');
try {
    DefinitionCodec::encode($config);
    throw new LogicException('Out-of-scope publication was accepted on export');
} catch (RuntimeException $expected) {
    check(! ($expected instanceof LogicException), $expected->getMessage());
}
echo "Public path stability and disclosure tests OK\n";

$fixedConfig->getMetadata()->set('PublicApi', 'yes');
$config->getMetadata()->set('PublicApiScope', 'published');
$fixedConfig->getMetadata()->set('PublicApiPath', 'fixed-kubernetes');
$storage->storeProcess($fixedConfig);
$fixedHealth = $health->config('fixed-kubernetes');
check(count($fixedHealth['components'] ?? []) === 1, 'Explicit Kubernetes selection must contribute to enabled public health');
$fixed->setExplicit(false);
check(! PublicHealthService::isPublishedNode($fixed), 'Discovered Kubernetes children must remain private');
$fixed->setExplicit(true);
echo "Explicit Kubernetes public health selection OK\n";
