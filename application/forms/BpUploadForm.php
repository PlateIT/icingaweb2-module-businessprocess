<?php

// SPDX-FileCopyrightText: 2018 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\Forms;

use Exception;
use Icinga\Module\Businessprocess\BpConfig;
use Icinga\Module\Businessprocess\PublicHealth\PublicHealthService;
use Icinga\Module\Businessprocess\Storage\DefinitionCodec;
use Icinga\Module\Businessprocess\Web\Form\BpConfigBaseForm;
use Icinga\Web\Notification;
use RuntimeException;

class BpUploadForm extends BpConfigBaseForm
{
    protected $node;

    protected $objectList = array();

    protected $processList = array();

    protected $deleteButtonName;

    private $definitionJson;

    /** @var BpConfig */
    private $uploadedConfig;

    public function setup()
    {
        $this->showUpload();
        if ($this->hasDefinition()) {
            $this->showDetails();
        }
    }

    protected function showDetails()
    {
        $this->addElement('text', 'name', array(
            'label'       => $this->translate('Name'),
            'required'    => true,
            'description' => $this->translate(
                'This is the unique identifier of this process'
            ),
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
                        'pattern'   => '/^[a-zA-Z0-9](?:[\w\h._-]*)?\w$/',
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
        ));

        $this->addElement('textarea', 'definition', array(
            'label'       => $this->translate('Definition'),
            'description' => $this->translate(
                'Structured Business Process JSON definition'
            ),
            'value' => $this->definitionJson,
            'class' => 'preformatted smaller',
            'rows'  => 7,
        ));

        $this->getUploadedConfig();

        $this->setSubmitLabel(
            $this->translate('Store')
        );
    }

    public function getUploadedConfig()
    {
        if ($this->uploadedConfig === null) {
            $this->uploadedConfig = $this->parseSubmittedDefinition();
        }

        return $this->uploadedConfig;
    }

    protected function parseSubmittedDefinition()
    {
        $code = $this->getSentValue('definition');
        $name = $this->getSentValue('name', '<new config>');
        if (empty($code)) {
            $code = $this->definitionJson;
        }

        try {
            $definition = json_decode($code, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($definition)) {
                throw new \RuntimeException('The Business Process definition must be a JSON object');
            }
            $config = DefinitionCodec::decode($name, $definition);

            if ($config->hasErrors()) {
                foreach ($config->getErrors() as $error) {
                    $this->addError($error);
                }
            }
        } catch (Exception $e) {
            $this->addError($e->getMessage());
            return null;
        }

        return $config;
    }

    protected function hasDefinition()
    {
        if ($this->hasBeenSent() && $definition = $this->getSentValue('definition')) {
            $this->definitionJson = $definition;
        } else {
            $this->processUploadedDefinition();
        }

        if (empty($this->definitionJson)) {
            return false;
        } else {
            $this->removeElement('uploaded_file');
            return true;
        }
    }

    protected function showUpload()
    {
        $this->setAttrib('enctype', 'multipart/form-data');

        $this->addElement('file', 'uploaded_file', array(
            'label'       => $this->translate('File'),
            'destination' => $this->getTempDir(),
            'required'    => true,
        ));

        /** @var \Zend_Form_Element_File $el */
        $el = $this->getElement('uploaded_file');
        $el->setValueDisabled(true);
        $el->setAttrib('required', true);

        $this->setSubmitLabel(
            $this->translate('Next')
        );
    }

    protected function getTempDir()
    {
        return sys_get_temp_dir();
    }

    protected function processUploadedDefinition()
    {
        /** @var ?\Zend_Form_Element_File $el */
        $el = $this->getElement('uploaded_file');

        if ($el && $this->hasBeenSent()) {
            $tmpdir = $this->getTempDir();
            $tmpfile = tempnam($tmpdir, 'bpupload_');

            // TODO: race condition, try to do this without unlinking here
            unlink($tmpfile);

            $el->addFilter('Rename', $tmpfile);
            if ($el->receive()) {
                $this->definitionJson = file_get_contents($tmpfile);
                unlink($tmpfile);
            } else {
                foreach ($el->file->getMessages() as $error) {
                    $this->addError($error);
                }
            }
        }

        return $this;
    }

    public function onSuccess()
    {
        $config = $this->getUploadedConfig();
        $name = $config->getName();

        if ($this->storage->hasProcess($name)) {
            $this->addError(sprintf(
                $this->translate('A process named "%s" already exists'),
                $name
            ));

            return;
        }

        if (! $this->prepareMetadata($config)) {
            return;
        }
        if (! $this->validatePublicHealth($config)) {
            return;
        }

        $this->storage->storeProcess($config);
        Notification::success(sprintf($this->translate('Process %s has been stored'), $name));

        $this->getSuccessUrl()->setParam('config', $name);

        parent::onSuccess();
    }

    protected function validatePublicHealth(BpConfig $config): bool
    {
        $metadata = $config->getMetadata();
        if (! $metadata->isPublicApiEnabled()) {
            return true;
        }
        if (
            ! in_array($metadata->getPublicApiScope(), ['roots', 'published'], true)
            || ! in_array($metadata->getPublicApiRelations(), ['none', 'links'], true)
        ) {
            $this->addError($this->translate('The public health API settings are invalid'));
            return false;
        }
        $configPath = PublicHealthService::pathForConfig($config);
        foreach ($this->storage->listAllProcessNames() as $name) {
            if ($name === $config->getName()) {
                continue;
            }
            $other = $this->storage->loadProcess($name);
            if ($other->getMetadata()->isPublicApiEnabled()
                && PublicHealthService::pathForConfig($other) === $configPath
            ) {
                $this->addError($this->translate('The generated public health path is already in use'));
                return false;
            }
        }
        try {
            PublicHealthService::assertValidNodePaths($config, $metadata->getPublicApiScope());
        } catch (RuntimeException $_) {
            $this->addError($this->translate('A published process node has invalid public health settings'));
            return false;
        }

        return true;
    }
}
