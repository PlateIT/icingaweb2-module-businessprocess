<?php

// SPDX-FileCopyrightText: 2018 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\Forms;

use Exception;
use Icinga\Module\Businessprocess\BpConfig;
use Icinga\Module\Businessprocess\BpNode;
use Icinga\Module\Businessprocess\Common\Sort;
use Icinga\Module\Businessprocess\Kubernetes\Feature as KubernetesFeature;
use Icinga\Module\Businessprocess\Kubernetes\Kind as KubernetesKind;
use Icinga\Module\Businessprocess\Kubernetes\NodeName as KubernetesNodeName;
use Icinga\Module\Businessprocess\Kubernetes\ObjectRepository as KubernetesObjectRepository;
use Icinga\Module\Businessprocess\Modification\ProcessChanges;
use Icinga\Module\Businessprocess\Node;
use Icinga\Module\Businessprocess\Storage\Storage;
use Icinga\Module\Businessprocess\Web\Form\Element\IplStateOverrides;
use Icinga\Module\Businessprocess\Web\Form\Validator\HostServiceTermValidator;
use Icinga\Web\Session\SessionNamespace;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Stdlib\Str;
use ipl\Web\Compat\CompatForm;
use ipl\Web\FormElement\TermInput;
use ipl\Web\Url;

class AddNodeForm extends CompatForm
{
    use Sort;
    use Translation;

    /** @var Storage */
    protected $storage;

    /** @var ?BpConfig */
    protected $bp;

    /** @var ?BpNode */
    protected $parent;

    /** @var SessionNamespace */
    protected $session;

    /** @var array */
    protected $submittedValues = [];

    /**
     * Set the storage to use
     *
     * @param Storage $storage
     *
     * @return $this
     */
    public function setStorage(Storage $storage): self
    {
        $this->storage = $storage;

        return $this;
    }

    /**
     * Set the affected configuration
     *
     * @param BpConfig $bp
     *
     * @return $this
     */
    public function setProcess(BpConfig $bp): self
    {
        $this->bp = $bp;

        return $this;
    }

    /**
     * Set the affected sub-process
     *
     * @param ?BpNode $node
     *
     * @return $this
     */
    public function setParentNode(?BpNode $node = null): self
    {
        $this->parent = $node;

        return $this;
    }

    /**
     * Set the user's session
     *
     * @param SessionNamespace $session
     *
     * @return $this
     */
    public function setSession(SessionNamespace $session): self
    {
        $this->session = $session;

        return $this;
    }

    protected function assemble()
    {
        if ($this->parent !== null) {
            $title = sprintf($this->translate('Add a node to %s'), $this->parent->getAlias());
            $nodeTypes = [
                'host' => $this->translate('Host'),
                'service' => $this->translate('Service'),
                'process' => $this->translate('Existing Process'),
                'new-process' => $this->translate('New Process')
            ];
        } else {
            $title = $this->translate('Add a new root node');
            if (! $this->bp->isEmpty()) {
                $nodeTypes = [
                    'process' => $this->translate('Existing Process'),
                    'new-process' => $this->translate('New Process')
                ];
            } else {
                $nodeTypes = [];
            }
        }

        if (KubernetesFeature::isEnabled() && KubernetesObjectRepository::canAccessAny()) {
            $nodeTypes['kubernetes'] = $this->translate('Kubernetes Object');
            $nodeTypes['kubernetes-selector'] = $this->translate('Dynamic Kubernetes Selection');
        }

        $this->addHtml(new HtmlElement('h2', null, Text::create($title)));

        if (! empty($nodeTypes)) {
            $this->addElement('select', 'node_type', [
                'label' => $this->translate('Node type'),
                'options' => array_merge(
                    ['' => ' - ' . $this->translate('Please choose') . ' - '],
                    $nodeTypes
                ),
                'disabledOptions' => [''],
                'class' => 'autosubmit',
                'required' => true,
                'ignore' => true
            ]);

            $nodeType = $this->getPopulatedValue('node_type');
        } else {
            $nodeType = 'new-process';
        }

        if ($nodeType === 'new-process') {
            $this->assembleNewProcessElements();
        } elseif ($nodeType === 'process') {
            $this->assembleExistingProcessElements();
        } elseif ($nodeType === 'host') {
            $this->assembleHostElements();
        } elseif ($nodeType === 'service') {
            $this->assembleServiceElements();
        } elseif ($nodeType === 'kubernetes') {
            $this->assembleKubernetesElements();
        } elseif ($nodeType === 'kubernetes-selector') {
            $this->assembleKubernetesSelectorElements();
        }

        $this->addElement('submit', 'submit', [
            'label' => $this->translate('Add Process')
        ]);
    }

    protected function assembleNewProcessElements(): void
    {
        $this->addElement('text', 'name', [
            'required'     => true,
            'ignore'       => true,
            'label'        => $this->translate('ID'),
            'description'  => $this->translate('This is the unique identifier of this process'),
            'validators'   => [
                'callback' => function ($value, $validator) {
                    if ($this->parent !== null ? $this->parent->hasChild($value) : $this->bp->hasRootNode($value)) {
                        $validator->addMessage(
                            sprintf($this->translate('%s is already defined in this process'), $value)
                        );

                        return false;
                    }

                    return true;
                }
            ]
        ]);

        $this->addElement('text', 'alias', [
            'label'       => $this->translate('Display Name'),
            'description' => $this->translate(
                'Usually this name will be shown for this node. Equals ID if not given'
            ),
        ]);

        $this->addElement('select', 'operator', [
            'required'     => true,
            'label'        => $this->translate('Operator'),
            'multiOptions' => Node::getOperators()
        ]);

        $display = 1;
        if (! $this->bp->isEmpty() && $this->bp->getMetadata()->isManuallyOrdered()) {
            $rootNodes = self::applyManualSorting($this->bp->getRootNodes());
            $display = end($rootNodes)->getDisplay() + 1;
        }
        $this->addElement('select', 'display', [
            'required'     => true,
            'label'        => $this->translate('Visualization'),
            'description'  => $this->translate('Where to show this process'),
            'value'        => $this->parent !== null ? '0' : "$display",
            'multiOptions' => [
                "$display" => $this->translate('Toplevel Process'),
                '0' => $this->translate('Subprocess only'),
            ]
        ]);

        $this->addElement('text', 'infoUrl', [
            'label'       => $this->translate('Info URL'),
            'description' => $this->translate('URL pointing to more information about this node')
        ]);
    }

    protected function assembleExistingProcessElements(): void
    {
        $termValidator = function (array $terms) {
            foreach ($terms as $term) {
                /** @var TermInput\ValidatedTerm $term */
                $nodeName = $term->getSearchValue();
                if ($nodeName[0] === '@') {
                    if ($this->parent === null) {
                        $term->setMessage($this->translate('Imported nodes cannot be used as root nodes'));
                    } elseif (strpos($nodeName, ':') === false) {
                        $term->setMessage($this->translate('Missing node name'));
                    } else {
                        [$config, $nodeName] = Str::trimSplit(substr($nodeName, 1), ':', 2);
                        if (! $this->storage->hasProcess($config)) {
                            $term->setMessage($this->translate('Config does not exist or access has been denied'));
                        } else {
                            try {
                                $bp = $this->storage->loadProcess($config);
                            } catch (Exception $e) {
                                $term->setMessage(
                                    sprintf($this->translate('Cannot load config: %s'), $e->getMessage())
                                );
                            }

                            if (isset($bp)) {
                                if (! $bp->hasNode($nodeName)) {
                                    $term->setMessage($this->translate('No node with this name found in config'));
                                } else {
                                    $term->setLabel($bp->getNode($nodeName)->getLabel());
                                }
                            }
                        }
                    }
                } elseif (! $this->bp->hasNode($nodeName)) {
                    $term->setMessage($this->translate('No node with this name found in config'));
                } else {
                    $term->setLabel($this->bp->getNode($nodeName)->getLabel());
                }

                if ($this->parent !== null && $this->parent->hasChild($term->getSearchValue())) {
                    $term->setMessage($this->translate('Already defined in this process'));
                }

                if ($this->parent !== null && $term->getSearchValue() === $this->parent->getName()) {
                    $term->setMessage($this->translate('Results in a parent/child loop'));
                }
            }
        };

        $this->addElement(
            (new TermInput('children'))
                ->setRequired()
                ->setVerticalTermDirection()
                ->setLabel($this->translate('Process Nodes'))
                ->setSuggestionUrl(Url::fromPath('businessprocess/suggestions/process', [
                    'node' => isset($this->parent) ? $this->parent->getName() : null,
                    'config' => $this->bp->getName(),
                    'showCompact' => true,
                    '_disableLayout' => true
                ]))
                ->on(TermInput::ON_ENRICH, $termValidator)
                ->on(TermInput::ON_ADD, $termValidator)
                ->on(TermInput::ON_PASTE, $termValidator)
                ->on(TermInput::ON_SAVE, $termValidator)
        );
    }

    protected function assembleHostElements(): void
    {
        $this->addElement($this->createChildrenElementForObjects(
            $this->translate('Hosts'),
            'businessprocess/suggestions/icingadb-host'
        ));

        $this->addElement('checkbox', 'host_override', [
            'ignore' => true,
            'class' => 'autosubmit',
            'label' => $this->translate('Override Host State')
        ]);
        if ($this->getPopulatedValue('host_override') === 'y') {
            $this->addElement(new IplStateOverrides('stateOverrides', [
                'label' => $this->translate('State Overrides'),
                'options' => [
                    0 => $this->translate('UP'),
                    1 => $this->translate('DOWN'),
                    99 => $this->translate('PENDING')
                ]
            ]));
        }
    }

    protected function assembleServiceElements(): void
    {
        $this->addElement($this->createChildrenElementForObjects(
            $this->translate('Services'),
            'businessprocess/suggestions/icingadb-service'
        ));

        $this->addElement('checkbox', 'service_override', [
            'ignore' => true,
            'class' => 'autosubmit',
            'label' => $this->translate('Override Service State')
        ]);
        if ($this->getPopulatedValue('service_override') === 'y') {
            $this->addElement(new IplStateOverrides('stateOverrides', [
                'label' => $this->translate('State Overrides'),
                'options' => [
                    0 => $this->translate('OK'),
                    1 => $this->translate('WARNING'),
                    2 => $this->translate('CRITICAL'),
                    3 => $this->translate('UNKNOWN'),
                    99 => $this->translate('PENDING'),
                ]
            ]));
        }
    }


    protected function assembleKubernetesElements(): void
    {
        $clusterOptions = [];
        foreach (KubernetesObjectRepository::clusters() as $cluster => $source) {
            $clusterOptions[$cluster] = sprintf('%s (%s)', $cluster, $this->translate($source));
        }
        $this->addElement('select', 'kubernetes_cluster', [
            'label' => $this->translate('Kubernetes Cluster'),
            'multiOptions' => $clusterOptions,
            'class' => 'autosubmit',
            'required' => true,
            'ignore' => true
        ]);
        $cluster = $this->getPopulatedValue('kubernetes_cluster') ?: array_key_first($clusterOptions);

        $typePage = KubernetesObjectRepository::resourceTypes($cluster);
        $typeOptions = [];
        $types = [];
        foreach ($typePage['items'] as $type) {
            $key = self::encodeKubernetesType($type);
            $types[$key] = $type;
            $typeOptions[$key] = sprintf(
                '%s (%s/%s) — %d',
                $type['kind'],
                $type['group'] === '' ? 'core' : $type['group'],
                $type['version'],
                $type['count']
            );
        }

        $this->addElement('select', 'kubernetes_type', [
            'label' => $this->translate('Kubernetes Object Type'),
            'multiOptions' => $typeOptions,
            'class' => 'autosubmit',
            'required' => true,
            'ignore' => true
        ]);

        $typeKey = $this->getPopulatedValue('kubernetes_type') ?: array_key_first($types);
        if ($typeKey === null || ! isset($types[$typeKey])) {
            throw new Exception($this->translate('The selected Kubernetes object type is no longer available'));
        }
        $type = $types[$typeKey];
        $kind = KubernetesKind::canonicalize($type['kind']);

        $namespaceOptions = ['' => $this->translate('All available namespaces')];
        foreach (KubernetesObjectRepository::namespaces($cluster, $type) as $namespaceName) {
            $namespaceOptions[$namespaceName] = $namespaceName;
        }
        $this->addElement('select', 'kubernetes_namespace', [
            'label' => $this->translate('Namespace (optional)'),
            'multiOptions' => $namespaceOptions,
            'class' => 'autosubmit',
            'required' => false,
            'ignore' => true
        ]);
        $namespace = (string) $this->getPopulatedValue('kubernetes_namespace');
        if (! isset($namespaceOptions[$namespace])) {
            $namespace = '';
            $this->getElement('kubernetes_namespace')->setValue('');
        }

        $termValidator = function (array $terms) use ($type, $namespace) {
            foreach ($terms as $term) {
                $nodeName = $term->getSearchValue();
                $kubernetesNode = KubernetesNodeName::parse($nodeName);
                if ($kubernetesNode === null) {
                    $term->setMessage($this->translate('Invalid Kubernetes object'));
                    continue;
                }

                $cluster = (string) $this->getPopulatedValue('kubernetes_cluster');
                $object = KubernetesObjectRepository::fetch(
                    $kubernetesNode[0],
                    $kubernetesNode[1],
                    $cluster,
                    $type['group'],
                    $type['version']
                );
                if ($object === null) {
                    $term->setMessage($this->translate('Kubernetes object does not exist or access has been denied'));
                    continue;
                }
                if ($namespace !== '' && ($object->namespace ?? '') !== $namespace) {
                    $term->setMessage($this->translate('Select an object from the chosen namespace'));
                    continue;
                }

                $term->setLabel(implode(' / ', KubernetesObjectRepository::labelParts($kubernetesNode[0], $object)));

                if ($this->parent !== null && $this->parent->hasChild($nodeName)) {
                    $term->setMessage($this->translate('Already defined in this process'));
                }
            }
        };

        $this->addElement(
            (new TermInput('children'))
                ->setRequired()
                ->setLabel($this->translate('Kubernetes Objects'))
                ->setVerticalTermDirection()
                ->setSuggestionUrl(Url::fromPath('businessprocess/suggestions/kubernetes-object', [
                    'kind' => $kind,
                    'apiKind' => $type['kind'],
                    'group' => $type['group'],
                    'version' => $type['version'],
                    'cluster' => $cluster,
                    'namespace' => $namespace,
                    'showCompact' => true,
                    '_disableLayout' => true
                ]))
                ->on(TermInput::ON_ENRICH, $termValidator)
                ->on(TermInput::ON_ADD, $termValidator)
                ->on(TermInput::ON_PASTE, $termValidator)
                ->on(TermInput::ON_SAVE, $termValidator)
        );

        $this->addElement('checkbox', 'expandDependencies', [
            'label' => $this->translate('Expand Kubernetes dependencies'),
            'checked' => true
        ]);

        if ($kind === 'namespace') {
            $options = [];
            foreach (KubernetesKind::NAMESPACE_INCLUDE_OPTIONS as $option) {
                $options[$option] = $this->translate(ucwords(str_replace('_', ' ', $option)));
            }


            foreach ($options as $option => $label) {
                $this->addElement('checkbox', 'namespaceInclude_' . $option, [
                    'label' => $label,
                    'checked' => in_array($option, KubernetesKind::DEFAULT_NAMESPACE_INCLUDE, true)
                ]);
            }
        }
    }

    protected function assembleKubernetesSelectorElements(): void
    {
        $this->addElement('hidden', 'selector_id', [
            'value' => $this->getPopulatedValue('selector_id') ?: bin2hex(random_bytes(12)),
            'required' => true,
            'validators' => ['callback' => function ($value, $validator) {
                if (! is_string($value) || ! preg_match('~^[A-Za-z0-9_.-]{1,128}$~D', $value)) {
                    $validator->addMessage($this->translate('Invalid selection identifier'));
                    return false;
                }
                return true;
            }]
        ]);
        $this->addElement('text', 'selector_title', [
            'label' => $this->translate('Display name (optional)'),
            'placeholder' => $this->translate('For example: Payment services')
        ]);
        $values = [];
        foreach (\Icinga\Module\Businessprocess\Kubernetes\SelectorForm::FILTERS as $field) {
            $values[$field] = (string) $this->getPopulatedValue('selector_' . $field);
        }
        $values['states'] = (array) $this->getPopulatedValue('selector_states');
        $values['aggregation'] = $this->getPopulatedValue('selector_aggregation') ?: 'worst';
        $choices = \Icinga\Module\Businessprocess\Kubernetes\SelectorForm::choices($values);
        if ($this->getPopulatedValue('selector_cluster') === null) {
            $values['cluster'] = array_key_first($choices['clusters']) ?? '';
        }
        foreach (\Icinga\Module\Businessprocess\Kubernetes\SelectorForm::fields(
            $values, $choices, fn($text) => $this->translate($text), true
        ) as $name => [$type, $attributes]) {
            $this->addElement($type, $name, $attributes);
        }
    }

    private static function encodeKubernetesType(array $type): string
    {
        return rtrim(strtr(base64_encode(json_encode([
            $type['group'], $type['version'], $type['kind']
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private static function decodeKubernetesType(string $encoded): array
    {
        if ($encoded === '' || strlen($encoded) > 4096 || ! preg_match('/^[A-Za-z0-9_-]+$/D', $encoded)) {
            throw new Exception('Invalid Kubernetes object type');
        }
        $padding = (4 - strlen($encoded) % 4) % 4;
        $json = base64_decode(strtr($encoded . str_repeat('=', $padding), '-_', '+/'), true);
        $type = $json === false ? null : json_decode($json, true);
        if (! is_array($type)
            || ! array_is_list($type)
            || count($type) !== 3
            || array_filter($type, 'is_string') !== $type
            || $type[1] === ''
            || $type[2] === ''
            || max(array_map('strlen', $type)) > 512
        ) {
            throw new Exception('Invalid Kubernetes object type');
        }

        return ['group' => $type[0], 'version' => $type[1], 'kind' => $type[2]];
    }

    protected function beforeValidation($data = array())
    {
        $this->submittedValues = $data;
    }

    protected function getNamespaceIncludeValues(): array
    {
        $include = [];
        foreach (KubernetesKind::NAMESPACE_INCLUDE_OPTIONS as $option) {
            if ((bool) $this->getValue('namespaceInclude_' . $option)) {
                $include[] = $option;
            }
        }

        return $include;
    }

    protected function extractSubmittedKubernetesNodeNames($value): array
    {
        $nodeNames = [];
        foreach ((array) $value as $entry) {
            if (is_array($entry)) {
                $nodeNames = array_merge($nodeNames, $this->extractSubmittedKubernetesNodeNames($entry));
                continue;
            }

            if (is_scalar($entry)) {
                preg_match_all('~kubernetes:[a-z][a-z0-9]{0,127}:[0-9a-f-]{36}~i', (string) $entry, $matches);
                $nodeNames = array_merge($nodeNames, $matches[0]);
            }
        }

        return array_values(array_unique($nodeNames));
    }

    protected function createChildrenElementForObjects(string $label, string $suggestionsPath): TermInput
    {
        $termValidator = function (array $terms) {
            (new HostServiceTermValidator())
                ->setParent($this->parent)
                ->isValid($terms);
        };

        return (new TermInput('children'))
            ->setRequired()
            ->setLabel($label)
            ->setVerticalTermDirection()
            ->setSuggestionUrl(Url::fromPath($suggestionsPath, [
                'node' => isset($this->parent) ? $this->parent->getName() : null,
                'config' => $this->bp->getName(),
                'showCompact' => true,
                '_disableLayout' => true
            ]))
            ->on(TermInput::ON_ENRICH, $termValidator)
            ->on(TermInput::ON_ADD, $termValidator)
            ->on(TermInput::ON_PASTE, $termValidator)
            ->on(TermInput::ON_SAVE, $termValidator);
    }

    protected function onSuccess()
    {
        $changes = ProcessChanges::construct($this->bp, $this->session);

        $nodeType = $this->getPopulatedValue('node_type');
        if (! $nodeType || $nodeType === 'new-process') {
            $properties = $this->getValues();
            if (! $properties['alias']) {
                unset($properties['alias']);
            }

            if ($this->parent !== null) {
                $properties['parentName'] = $this->parent->getName();
            }

            $changes->createNode(BpConfig::escapeName($this->getValue('name')), $properties);
        } elseif ($nodeType === 'kubernetes-selector') {
            $selector = [];
            foreach (['cluster','group','version','kind','namespace','name','labels','ownerUID'] as $field) {
                $value = trim((string) $this->getValue('selector_' . $field));
                if ($value !== '') { $selector[$field] = $value; }
            }
            $states = array_values(array_intersect(
                (array) $this->getValue('selector_states'),
                ['ok', 'warning', 'critical', 'unknown']
            ));
            if ($states !== []) { $selector['states'] = $states; }
            $nodeName = 'kubernetes-selector:' . $this->getValue('selector_id');
            $properties = ['selector' => $selector, 'aggregation' => $this->getValue('selector_aggregation')];
            $properties['alias'] = trim((string) $this->getValue('selector_title'))
                ?: implode(' / ', array_filter([
                    $selector['kind'] ?? $this->translate('Kubernetes objects'), $selector['namespace'] ?? ''
                ]));
            if ($this->parent !== null) { $properties['parentName'] = $this->parent->getName(); } else { $properties['display'] = 1; }
            $changes->createNode($nodeName, $properties);
            unset($changes);
            return;
        } else {
            /** @var TermInput $term */
            $term = $this->getElement('children');
            $children = array_unique(array_map(function ($term) {
                return $term->getSearchValue();
            }, $term->getTerms()));

            if ($nodeType === 'kubernetes') {
                $type = self::decodeKubernetesType((string) $this->getPopulatedValue('kubernetes_type'));
                if (empty($children)) {
                    $children = $this->extractSubmittedKubernetesNodeNames($this->submittedValues['children'] ?? []);
                }

                $hasKubernetesNode = false;
                foreach ($children as $nodeName) {
                    if (! ($kubernetesNode = KubernetesNodeName::parse($nodeName))) {
                        throw new Exception(sprintf(
                            $this->translate('Invalid Kubernetes object selection: %s'),
                            $nodeName
                        ));
                    }

                    $hasKubernetesNode = true;

                    $object = KubernetesObjectRepository::fetch(
                        $kubernetesNode[0], $kubernetesNode[1],
                        (string) $this->getPopulatedValue('kubernetes_cluster'),
                        $type['group'], $type['version']
                    );
                    $namespace = (string) $this->getPopulatedValue('kubernetes_namespace');
                    if ($object === null || ($namespace !== '' && ($object->namespace ?? '') !== $namespace)) {
                        throw new Exception($this->translate('The selected object does not match the current namespace filter'));
                    }

                    if ($this->bp->hasNode($nodeName)) {
                        if ($this->parent !== null) {
                            $changes->addChildrenToNode([$nodeName], $this->parent);
                        } else {
                            $changes->copyNode($nodeName);
                        }
                        continue;
                    }

                    $properties = [
                        'kind' => $kubernetesNode[0],
                        'uuid' => $kubernetesNode[1],
                        'expectedCluster' => (string) $this->getPopulatedValue('kubernetes_cluster'),
                        'expectedGroup' => $type['group'],
                        'expectedVersion' => $type['version'],
                        'apiKind' => $type['kind'],
                        'expandDependencies' => (bool) $this->getValue('expandDependencies'),
                        'namespaceInclude' => $this->getNamespaceIncludeValues(),
                    ];
                    if ($this->parent !== null) {
                        $properties['parentName'] = $this->parent->getName();
                    } else {
                        $properties['display'] = 1;
                    }
                    $changes->createNode($nodeName, $properties);
                }
                if (! $hasKubernetesNode) {
                    throw new Exception($this->translate('Please select at least one Kubernetes object'));
                }
                unset($changes);
                return;
            }

            if ($nodeType === 'host' || $nodeType === 'service') {
                $stateOverrides = $this->getValue('stateOverrides');
                if (! empty($stateOverrides)) {
                    $childOverrides = [];
                    foreach ($children as $nodeName) {
                        $childOverrides[$nodeName] = $stateOverrides;
                    }

                    $changes->modifyNode($this->parent, [
                        'stateOverrides' => array_merge($this->parent->getStateOverrides(), $childOverrides)
                    ]);
                }
            }

            if ($this->parent !== null) {
                $changes->addChildrenToNode($children, $this->parent);
            } else {
                foreach ($children as $nodeName) {
                    $changes->copyNode($nodeName);
                }
            }
        }

        unset($changes);
    }
}
