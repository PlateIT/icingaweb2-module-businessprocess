<?php

// SPDX-FileCopyrightText: 2018 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\Controllers;

use Icinga\Web\Url;
use ipl\Web\Compat\CompatController;

class ServiceController extends CompatController
{
    public function showAction(): void
    {
        $hostName = $this->params->shift('host');
        $serviceName = $this->params->shift('service');

        $this->params->add('name', $serviceName);
        $this->params->add('host.name', $hostName);
        $this->redirectNow(Url::fromPath('icingadb/service')->setParams($this->params));
    }
}
