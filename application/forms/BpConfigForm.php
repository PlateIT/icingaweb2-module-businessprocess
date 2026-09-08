<?php

// SPDX-FileCopyrightText: 2018 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\Forms;

use Icinga\Authentication\Auth;
use Icinga\Module\Businessprocess\BpConfig;
use Icinga\Module\Businessprocess\PublicHealth\PublicHealthService;
use Icinga\Module\Businessprocess\PublicHealth\Settings as PublicHealthSettings;
use Icinga\Module\Businessprocess\Web\Form\BpConfigBaseForm;
use RuntimeException;

class BpConfigForm extends BpConfigBaseForm
{
    protected $deleteButtonName;

    public function setup()
    {
        $this->addElement('text', 'name', array(
            'label' => $this->translate('ID'),
            'required'    => true,
            'validators' => array(
                array(
                    'validator' => 'StringLength',
                    'options' => array(
                        'min' => 2,
                        'max' => 40
                    )
                ),
                [
                    'validator' => 'Regex',
                    'options'   => [
                        'pattern' => '/^[a-zA-Z0-9](?:[\w\h._-]*)?\w$/',
                        'messages'  => [
                            'regexNotMatch' => $this->translate(
                                'Id must only consist of alphanumeric characters.'
                                . ' Underscore at the beginning and space, dot and hyphen at the beginning'
                                . ' and end are not allowed.'
                            )
                        ]
                    ]
                ]
            ),
            'description' => $this->translate(
                'This is the unique identifier of this process'
            ),
        ));

        $this->addElement('text', 'Title', array(
            'label'       => $this->translate('Display Name'),
            'description' => $this->translate(
                'Usually this name will be shown for this process. Equals ID'
                . ' if not given'
            ),
        ));

        $this->addElement('textarea', 'Description', array(
            'label'       => $this->translate('Description'),
            'description' => $this->translate(
                'A slightly more detailed description for this process, about 100-150 characters long'
            ),
            'rows' => 4,
        ));

        $this->addElement('select', 'Statetype', array(
            'label'       => $this->translate('State Type'),
            'required'    => true,
            'description' => $this->translate(
                'Whether this process should be based on Icinga hard or soft states'
            ),
            'multiOptions' => array(
                'soft' => $this->translate('Use SOFT states'),
                'hard' => $this->translate('Use HARD states'),
            )
        ));

        $this->addElement('select', 'AddToMenu', array(
            'label'       => $this->translate('Add to menu'),
            'required'    => true,
            'description' => $this->translate(
                'Whether this process should be linked in the main Icinga Web 2 menu'
            ),
            'multiOptions' => array(
                'yes' => $this->translate('Yes'),
                'no'  => $this->translate('No'),
            )
        ));

        $this->addElement('text', 'PublicApiAvailability', [
            'label' => $this->translate('Public API availability'),
            'value' => PublicHealthSettings::isEnabled()
                ? $this->translate('Available globally')
                : $this->translate('Disabled globally'),
            'disabled' => true,
            'description' => PublicHealthSettings::isEnabled()
                ? $this->translate('The global API is enabled. The overall status includes all root nodes. Public Node Scope controls which node details are exposed.')
                : $this->translate('The global API is disabled in the deployment. Enable businessProcess.publicHealth.enabled in the Helm values.')
        ]);

        $this->addElement('select', 'PublicApi', [
            'label' => $this->translate('Public Health API'),
            'required' => true,
            'description' => $this->translate(
                'Anonymous access: also enable Publish health status on each process node to expose it.'
                . ' Allowed users, groups and roles below do not restrict this public API.'
            ),
            'multiOptions' => [
                'no' => $this->translate('Disabled'),
                'yes' => $this->translate('Enabled')
            ]
        ]);

        $this->addElement('text', 'PublicApiPath', [
            'label' => $this->translate('Public Health Path'),
            'description' => $this->translate(
                'URL: /businessprocess/health/<path>. Prefilled from the ID and stored independently of the display name.'
                . ' Use lowercase letters, numbers and hyphens (maximum 63 characters).'
                . ' Changing this path changes the public URL.'
            ),
            'maxlength' => 63,
            'placeholder' => 'icinga',
            'data-public-health-path' => '1'
        ]);

        $this->addElement('select', 'PublicApiScope', [
            'label' => $this->translate('Public Node Scope'),
            'required' => true,
            'multiOptions' => [
                'roots' => $this->translate('Explicitly published root nodes only'),
                'published' => $this->translate('All explicitly published process nodes')
            ]
        ]);

        $this->addElement('select', 'PublicApiRelations', [
            'label' => $this->translate('Public Relations'),
            'required' => true,
            'multiOptions' => [
                'none' => $this->translate('Do not expose relations'),
                'links' => $this->translate('Link published parents and children')
            ]
        ]);

        $this->addElement('text', 'AllowedUsers', array(
            'label'       => $this->translate('Allowed Users'),
            'description' => $this->translate(
                'Allowed Users (comma-separated)'
            ),
        ));

        $this->addElement('text', 'AllowedGroups', array(
            'label'       => $this->translate('Allowed Groups'),
            'description' => $this->translate(
                'Allowed Groups (comma-separated)'
            ),
        ));

        $this->addElement('text', 'AllowedRoles', array(
            'label'       => $this->translate('Allowed Roles'),
            'description' => $this->translate(
                'Allowed Roles (comma-separated)'
            ),
        ));

        if ($this->bp === null) {
            $this->setSubmitLabel(
                $this->translate('Add')
            );
        } else {
            $config = $this->bp;

            $meta = $config->getMetadata();
            foreach ($meta->getProperties() as $k => $v) {
                if ($el = $this->getElement($k)) {
                    $el->setValue($v);
                }
            }
            $this->getElement('PublicApiPath')->setValue(
                PublicHealthService::pathForConfig($config)
            );
            $this->getElement('name')
                 ->setValue($config->getName())
                 ->setAttrib('readonly', true);

            $this->setSubmitLabel(
                $this->translate('Store')
            );

            $label = $this->translate('Delete');
            $el = $this->createElement('submit', $label, array(
                'data-base-target' => '_main'
            ))->setLabel($label)->setDecorators(array('ViewHelper'));
            $this->deleteButtonName = $el->getName();
            $this->addElement($el);
        }
    }

    protected function onSetup()
    {
        $this->getElement($this->getSubmitLabel())->setAttrib('data-base-target', '_main');
    }

    protected function onRequest()
    {
        $name = $this->getValue('name');

        if ($this->shouldBeDeleted()) {
            if ($this->bp->isReferenced()) {
                $this->addError(sprintf(
                    $this->translate('Process "%s" cannot be deleted as it has been referenced in other processes'),
                    $name
                ));
            } else {
                $this->bp->clearAppliedChanges();
                $this->storage->deleteProcess($name);
                $this->setSuccessUrl('businessprocess');
                $this->redirectOnSuccess(sprintf($this->translate('Process %s has been deleted'), $name));
            }
        }
    }

    public function onSuccess()
    {
        $name = $this->getValue('name');

        if (! $this->validatePublicApiSettings($name)) {
            return;
        }

        if ($this->bp === null) {
            if ($this->storage->hasProcess($name)) {
                $this->addError(sprintf(
                    $this->translate('A process named "%s" already exists'),
                    $name
                ));

                return;
            }

            // New config
            $config = new BpConfig();
            $config->setName($name);

            if (! $this->prepareMetadata($config)) {
                return;
            }

            $this->setSuccessUrl(
                $this->getSuccessUrl()->setParams(
                    array('config' => $name, 'unlocked' => true)
                )
            );
            $this->setSuccessMessage(sprintf($this->translate('Process %s has been created'), $name));
        } else {
            $config = $this->bp;
            $this->setSuccessMessage(sprintf($this->translate('Process %s has been stored'), $name));
        }
        $meta = $config->getMetadata();
        foreach ($this->getValues() as $key => $value) {
            if (
                ! in_array($key, ['Title', 'Description'], true)
                && ($value === null || $value === '')
            ) {
                continue;
            }

            if ($meta->hasKey($key)) {
                $meta->set($key, $value);
            }
        }

        $this->storage->storeProcess($config);
        $config->clearAppliedChanges();
        parent::onSuccess();
    }

    protected function validatePublicApiSettings(string $name): bool
    {
        $enabled = $this->getValue('PublicApi') === 'yes';
        $path = trim((string) $this->getValue('PublicApiPath'));
        if ($path === '') {
            $path = $this->bp === null
                ? PublicHealthService::slug($name)
                : PublicHealthService::pathForConfig($this->bp);
        }
        $this->getElement('PublicApiPath')->setValue($path);
        if (! PublicHealthService::isValidConfigPath($path)) {
            $this->getElement('PublicApiPath')->addError(
                $this->translate('Use 1 to 63 lowercase letters, numbers and single hyphens between words')
            );
            return false;
        }

        if ($enabled) {
            foreach ($this->storage->listAllProcessNames() as $otherName) {
                if ($otherName === $name) {
                    continue;
                }
                $other = $this->storage->loadMetadata($otherName);
                if ($other->isPublicApiEnabled() && PublicHealthService::pathForMetadata($other) === $path) {
                    $this->getElement('PublicApiPath')->addError(
                        $this->translate('This public health path is already in use')
                    );
                    return false;
                }
            }

            if ($this->bp !== null) {
                try {
                    PublicHealthService::assertValidNodePaths(
                        $this->bp,
                        (string) $this->getValue('PublicApiScope')
                    );
                } catch (RuntimeException $_) {
                    $this->getElement('PublicApiScope')->addError(
                        $this->translate('Published process display names must generate unique valid paths')
                    );
                    return false;
                }
            }
        }

        return true;
    }

    public function hasDeleteButton()
    {
        return $this->deleteButtonName !== null;
    }

    public function shouldBeDeleted()
    {
        if (! $this->hasDeleteButton()) {
            return false;
        }

        $name = $this->deleteButtonName;
        return $this->getSentValue($name) === $this->getElement($name)->getLabel();
    }
}
