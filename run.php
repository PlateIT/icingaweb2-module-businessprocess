<?php

// SPDX-FileCopyrightText: 2018 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

$this->addRoute('public-health', new Zend_Controller_Router_Route_Static(
    'health',
    [
        'controller' => 'central-health',
        'action' => 'index',
        'module' => 'businessprocess'
    ]
));
$this->addRoute('businessprocess/public-health', new Zend_Controller_Router_Route_Static(
    'businessprocess/health',
    [
        'controller' => 'health',
        'action' => 'index',
        'module' => 'businessprocess'
    ]
));
$this->addRoute('businessprocess/public-health/config', new Zend_Controller_Router_Route(
    'businessprocess/health/:config',
    [
        'controller' => 'health',
        'action' => 'config',
        'module' => 'businessprocess'
    ]
));
$this->addRoute('businessprocess/public-health/node', new Zend_Controller_Router_Route_Regex(
    'businessprocess/health/(.+/.+)',
    [
        'controller' => 'health',
        'action' => 'node',
        'module' => 'businessprocess'
    ],
    ['path' => 1],
    'businessprocess/health/%s'
));

$this->provideHook('icingadb/HostActions');
$this->provideHook('icingadb/ServiceActions');
$this->provideHook('icingadb/icingadbSupport');
$this->provideHook('icingadb/ServiceDetailExtension');
