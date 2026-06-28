<?php

// SPDX-FileCopyrightText: 2018 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\Modification;

use Icinga\Module\Businessprocess\BpConfig;
use Icinga\Module\Businessprocess\BpNode;
use Icinga\Module\Businessprocess\Kubernetes\NodeName;
use Icinga\Module\Businessprocess\Kubernetes\ObjectRepository;
use Icinga\Module\Businessprocess\KubernetesNode;
use Icinga\Module\Businessprocess\Node;

class NodeCreateAction extends NodeAction
{
    /** @var string */
    protected $parentName;

    /** @var array */
    protected $properties = array();

    /** @var array */
    protected $preserveProperties = array('parentName', 'properties');

    /**
     * @param Node $name
     */
    public function setParent(Node $name)
    {
        $this->parentName = $name->getName();
    }

    /**
     * @return bool
     */
    public function hasParent()
    {
        return $this->parentName !== null;
    }

    /**
     * @return string
     */
    public function getParentName()
    {
        return $this->parentName;
    }

    /**
     * @param string $name
     */
    public function setParentName($name)
    {
        $this->parentName = $name;
    }

    /**
     * @return array
     */
    public function getProperties()
    {
        return $this->properties;
    }

    /**
     * @param array $properties
     * @return $this
     */
    public function setProperties($properties)
    {
        $this->properties = (array) $properties;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function appliesTo(BpConfig $config)
    {
        $name = $this->getNodeName();
        if ($config->hasNode($name)) {
            $this->error('A node with name "%s" already exists', $name);
        }

        $parent = $this->getParentName();
        if ($parent !== null && ! $config->hasBpNode($parent)) {
            $this->error('Parent process "%s" missing', $parent);
        }

        $kubernetesNode = NodeName::parse($name);
        if ($kubernetesNode !== null || isset($this->properties['kind'], $this->properties['uuid'])) {
            $kind = $this->properties['kind'] ?? $kubernetesNode[0];
            $uuid = $this->properties['uuid'] ?? $kubernetesNode[1];
            if (ObjectRepository::fetch($kind, $uuid) === null) {
                $this->error('Kubernetes object "%s" does not exist or access has been denied', $name);
            }
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function applyTo(BpConfig $config)
    {
        $name = $this->getNodeName();
        $kubernetesNode = NodeName::parse($name);

        if ($kubernetesNode !== null || isset($this->properties['kind'], $this->properties['uuid'])) {
            $kind = $this->properties['kind'] ?? $kubernetesNode[0];
            $uuid = $this->properties['uuid'] ?? $kubernetesNode[1];
            $node = $config->createKubernetesNode($kind, $uuid);
        } else {
            $properties = array(
                'name'        => $name,
                'operator'    => $this->properties['operator'],
            );
            if (array_key_exists('childNames', $this->properties)) {
                $properties['child_names'] = $this->properties['childNames'];
            } else {
                $properties['child_names'] = array();
            }
            $node = new BpNode((object) $properties);
            $node->setBpConfig($config);
        }

        foreach ($this->getProperties() as $key => $val) {
            if (in_array($key, ['kind', 'uuid', 'node_type'], true)) {
                continue;
            }
            if ($key === 'expandDependencies') {
                if ($node instanceof KubernetesNode) {
                    $node->setExpandDependencies((bool) $val);
                }
                continue;
            }
            if ($key === 'namespaceInclude') {
                if ($node instanceof KubernetesNode) {
                    $node->setNamespaceInclude((array) $val);
                }
                continue;
            }
            if ($key === 'parentName') {
                $config->getBpNode($val)->addChild($node);
                continue;
            }
            $func = 'set' . ucfirst($key);
            $node->$func($val);
        }

        if ($node->getDisplay() > 1) {
            $i = $node->getDisplay();
            foreach ($config->getRootNodes() as $_ => $rootNode) {
                if ($rootNode->getDisplay() >= $node->getDisplay()) {
                    $rootNode->setDisplay(++$i);
                }
            }
        }

        $config->addNode($name, $node);

        return $node;
    }
}
