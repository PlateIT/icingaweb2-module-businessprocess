<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace Icinga\Module\Businessprocess\Controllers;

use Icinga\Module\Businessprocess\Kubernetes\SelectorPreview;
use Icinga\Module\Businessprocess\Kubernetes\SelectorForm;
use Icinga\Module\Kubernetes\Api\Client;
use Icinga\Module\Kubernetes\Authorization\ResourceAccess;
use Icinga\Web\Controller;
use Throwable;

class SelectorpreviewController extends Controller
{
    public function indexAction(): void
    {
        $this->assertPermission('kubernetes/resources/show');
        $this->_helper->viewRenderer->setNoRender(true);
        $this->_helper->layout()->disableLayout();
        $this->getResponse()->setHeader('Content-Type', 'application/json', true)->setHeader('Cache-Control', 'no-store', true);
        try {
            $input = [];
            foreach (array_merge(SelectorForm::FILTERS, ['states']) as $name) $input[$name] = $this->getRequest()->getQuery($name, $name === 'states' ? [] : '');
            $access = new ResourceAccess();
            $roles = $access->selectors();
            $requests = SelectorPreview::queries($input, $roles);
            $items = []; $more = false;
            $client = new Client();
            foreach (array_chunk($requests, 16) as $batch) foreach ($client->getMany($batch) as $page) {
                $more = $more || ! empty($page['nextCursor']);
                foreach ($page['items'] as $item) {
                    if (! $access->permits($item, $roles)) continue;
                    $items[$item['id']] = array_intersect_key($item, array_flip(['id', 'name', 'namespace', 'kind', 'state']));
                }
            }
            $more = $more || count($items) > 50;
            $this->getResponse()->setBody(json_encode(['items' => array_slice(array_values($items), 0, 50), 'more' => $more], JSON_THROW_ON_ERROR));
        } catch (Throwable $error) {
            $this->getResponse()->setHttpResponseCode($error instanceof \InvalidArgumentException ? 400 : 503);
            $this->getResponse()->setBody(json_encode(['error' => $this->translate('Preview unavailable. Check the filters and inventory availability.')]));
        }
    }
}
