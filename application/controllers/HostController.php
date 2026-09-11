<?php

// SPDX-FileCopyrightText: 2018 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\Controllers;

use Icinga\Web\Url;
use ipl\Web\Compat\CompatController;

class HostController extends CompatController
{
    public function showAction(): void
    {
        $hostName = $this->params->shift('host');

        $this->params->add('name', $hostName);
        $this->redirectNow(Url::fromPath('icingadb/host')->setParams($this->params));
    }
}
