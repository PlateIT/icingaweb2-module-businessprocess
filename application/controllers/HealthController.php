<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\Controllers;

use Icinga\Module\Businessprocess\PublicHealth\Controller;
use Icinga\Module\Businessprocess\PublicHealth\StatusMapper;
use Throwable;

class HealthController extends Controller
{
    public function indexAction(): void
    {
        if (! $this->assertReadOnly()) {
            return;
        }
        try {
            $payload = $this->health->catalog();
            $this->respond($payload, StatusMapper::httpStatus($payload['status']));
        } catch (Throwable $error) {
            $this->unavailable($error);
        }
    }

    public function configAction(): void
    {
        if (! $this->assertReadOnly()) {
            return;
        }
        try {
            $config = (string) $this->getRequest()->getParam('config', '');
            $payload = $this->health->config($config);
            if ($payload === null) {
                $this->notFound();
                return;
            }
            $this->respond($payload, StatusMapper::httpStatus($payload['status']));
        } catch (Throwable $error) {
            $this->unavailable($error);
        }
    }

    public function nodeAction(): void
    {
        if (! $this->assertReadOnly()) {
            return;
        }
        try {
            $path = (string) $this->getRequest()->getParam('path', '');
            $payload = $this->health->node($path);
            if ($payload === null) {
                $this->notFound();
                return;
            }
            $this->respond($payload, StatusMapper::httpStatus($payload['status']));
        } catch (Throwable $error) {
            $this->unavailable($error);
        }
    }
}
