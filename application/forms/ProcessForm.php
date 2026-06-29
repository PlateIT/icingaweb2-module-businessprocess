<?php

// SPDX-FileCopyrightText: 2018 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\Forms;

use Icinga\Module\Businessprocess\BpNode;
use Icinga\Module\Businessprocess\Kubernetes\Kind as KubernetesKind;
use Icinga\Module\Businessprocess\KubernetesNode;
use Icinga\Module\Businessprocess\Modification\ProcessChanges;
use Icinga\Module\Businessprocess\Node;
use Icinga\Module\Businessprocess\Web\Form\BpConfigBaseForm;
use Icinga\Web\Notification;
use Icinga\Web\View;

class ProcessForm extends BpConfigBaseForm
{
    /** @var BpNode */
    protected $node;

    public function setup()
    {
        if ($this->node !== null) {
            /** @var View $view */
            $view = $this->getView();

            $this->addHtml(
                '<h2>' . $view->escape(
                    sprintf($this->translate('Modify "%s"'), $this->node->getAlias())
                ) . '</h2>'
            );
        }

        $this->addElement('text', 'name', [
            'label'         => $this->translate('ID'),
            'value'         => (string) $this->node,
            'required'      => true,
            'readonly'      => $this->node ? true : null,
            'description'   => $this->translate('This is the unique identifier of this process')
        ]);

        $this->addElement('text', 'alias', array(
            'label'        => $this->translate('Display Name'),
            'description' => $this->translate(
                'Usually this name will be shown for this node. Equals ID'
                . ' if not given'
            ),
        ));

        $this->addElement('select', 'operator', array(
            'label'        => $this->translate('Operator'),
            'required'     => true,
            'multiOptions' => Node::getOperators()
        ));

        if ($this->node !== null) {
            $display = $this->node->getDisplay() ?: 1;
        } else {
            $display = 1;
        }
        $this->addElement('select', 'display', array(
            'label'        => $this->translate('Visualization'),
            'required'     => true,
            'description'  => $this->translate(
                'Where to show this process'
            ),
            'multiOptions' => array(
                "$display" => $this->translate('Toplevel Process'),
                '0' => $this->translate('Subprocess only'),
            )
        ));

        $urlElementOptions = array(
            'label'        => $this->translate('Info URL'),
            'description' => $this->translate(
                'URL pointing to more information about this node'
            )
        );
        if ($this->node instanceof KubernetesNode) {
            $urlElementOptions['readonly'] = true;
        }
        $this->addElement('text', 'url', $urlElementOptions);

        if ($this->node instanceof KubernetesNode) {
            $this->addKubernetesElements($this->node);
        }

        if ($node = $this->node) {
            if ($node->hasAlias()) {
                $alias = $node instanceof KubernetesNode ? $node->getStoredAlias() : $node->getAlias();
                $this->getElement('alias')->setValue($alias);
            }
            $this->getElement('operator')->setValue($node->getOperator());
            $this->getElement('display')->setValue($node->getDisplay());
            if ($node->hasInfoUrl()) {
                $this->getElement('url')->setValue($node->getInfoUrl());
            }
        }
    }


    protected function addKubernetesElements(KubernetesNode $node): void
    {
        $this->addElement('checkbox', 'expandDependencies', [
            'label' => $this->translate('Expand Kubernetes dependencies'),
            'checked' => $node->getExpandDependencies()
        ]);

        if ($node->getKind() === 'namespace') {
            $options = [];
            foreach (KubernetesKind::NAMESPACE_INCLUDE_OPTIONS as $option) {
                $options[$option] = $this->translate(ucwords(str_replace('_', ' ', $option)));
            }


            foreach ($options as $option => $label) {
                $this->addElement('checkbox', 'namespaceInclude_' . $option, [
                    'label' => $label,
                    'checked' => in_array($option, $node->getNamespaceInclude(), true)
                ]);
            }
        }
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
    /**
     * @param BpNode $node
     * @return $this
     */
    public function setNode(BpNode $node)
    {
        $this->node = $node;
        return $this;
    }

    public function onSuccess()
    {
        $changes = ProcessChanges::construct($this->bp, $this->session);

        $modifications = array();
        $alias    = $this->getValue('alias');
        $operator = $this->getValue('operator');
        $display  = $this->getValue('display');
        $url      = $this->getValue('url');
        if (empty($url)) {
            $url = null;
        }
        if (empty($alias)) {
            $alias = null;
        }
        // TODO: rename

        if ($node = $this->node) {
            if ($display !== $node->getDisplay()) {
                $modifications['display'] = $display;
            }
            if ($operator !== $node->getOperator()) {
                $modifications['operator'] = $operator;
            }
            if (! ($node instanceof KubernetesNode) && $url !== $node->getInfoUrl()) {
                $modifications['infoUrl'] = $url;
            }
            $currentAlias = $node instanceof KubernetesNode ? $node->getStoredAlias() : $node->getAlias();
            if ($alias !== $currentAlias) {
                $modifications['alias'] = $alias;
            }
            if ($node instanceof KubernetesNode) {
                $expandDependencies = (bool) $this->getValue('expandDependencies');
                if ($expandDependencies !== $node->getExpandDependencies()) {
                    $modifications['expandDependencies'] = $expandDependencies;
                }

                if ($node->getKind() === 'namespace') {
                    $namespaceInclude = $this->getNamespaceIncludeValues();
                    if ($namespaceInclude !== $node->getNamespaceInclude()) {
                        $modifications['namespaceInclude'] = $namespaceInclude;
                    }
                }
            }
        } else {
            $modifications = array(
                'display'    => $display,
                'operator'   => $operator,
                'infoUrl'    => $url,
                'alias'      => $alias,
            );
        }

        if (! empty($modifications)) {
            if ($this->node === null) {
                $changes->createNode($this->getValue('name'), $modifications);
            } else {
                $changes->modifyNode($this->node, $modifications);
            }

            Notification::success(
                sprintf(
                    $this->translate('Process %s has been modified'),
                    $this->bp->getName()
                )
            );
        }

        // Trigger session destruction to make sure it get's stored.
        // TODO: figure out why this is necessary, might be an unclean shutdown on redirect
        unset($changes);

        parent::onSuccess();
    }
}
