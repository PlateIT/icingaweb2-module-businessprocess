<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\PublicHealth;

use Icinga\Application\Logger;
use Icinga\Module\Businessprocess\Storage\ApiStorage;
use Icinga\Web\Controller as IcingaController;
use Throwable;

abstract class Controller extends IcingaController
{
    protected $requiresAuthentication = false;

    protected PublicHealthService $health;

    protected bool $enabled = false;

    public function init(): void
    {
        $this->_helper->viewRenderer->setNoRender(true);
        $this->_helper->layout()->disableLayout();
        $this->enabled = Settings::isEnabled();
        if ($this->enabled) {
            $this->health = new PublicHealthService(ApiStorage::getInstance());
        }
    }

    protected function assertReadOnly(): bool
    {
        if (! $this->enabled) {
            $this->notFound();
            return false;
        }
        if (! HttpContract::allows($this->getRequest()->getMethod())) {
            $this->getResponse()->setHeader('Allow', HttpContract::ALLOW, true);
            $this->respond(['status' => StatusMapper::UNKNOWN], 405);
            return false;
        }

        return true;
    }

    protected function respond(array $payload, int $status = 200): void
    {
        $body = HttpContract::encode($payload);
        $response = $this->getResponse();
        $response->setHttpResponseCode($status);
        foreach (HttpContract::headers() as $name => $value) {
            $response->setHeader($name, $value, true);
        }

        if (HttpContract::hasBody($this->getRequest()->getMethod())) {
            $response->setBody($body);
        }
    }

    protected function notFound(): void
    {
        $this->respond(['status' => StatusMapper::UNKNOWN], 404);
    }

    protected function unavailable(Throwable $error): void
    {
        Logger::error(
            'Business Process public health calculation failed (%s): %s',
            get_class($error),
            $error->getMessage()
        );
        $this->respond(['status' => StatusMapper::UNKNOWN], 503);
    }
}
