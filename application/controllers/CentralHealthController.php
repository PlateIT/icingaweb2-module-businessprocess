<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\Controllers;

use Icinga\Module\Businessprocess\PublicHealth\Controller;
use Throwable;

class CentralHealthController extends Controller
{
    public function indexAction(): void
    {
        if (! $this->assertReadOnly()) {
            return;
        }
        try {
            $this->respond($this->health->discovery());
        } catch (Throwable $error) {
            $this->unavailable($error);
        }
    }
}
