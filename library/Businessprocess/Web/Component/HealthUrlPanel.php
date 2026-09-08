<?php

// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\Web\Component;

use Icinga\Module\Businessprocess\BpConfig;
use Icinga\Module\Businessprocess\BpNode;
use Icinga\Module\Businessprocess\PublicHealth\PublicHealthService;
use Icinga\Module\Businessprocess\PublicHealth\Settings;
use Icinga\Web\Url;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;

/** Authenticated URL discovery only; this does not enable public access. */
class HealthUrlPanel extends BaseHtmlElement
{
    protected $tag = 'div';
    protected $defaultAttributes = ['class' => 'health-url-panel', 'hidden' => true];

    public function __construct(BpConfig $config)
    {
        $this->addAttributes(['data-health-config' => $config->getName()]);

        $meta = $config->getMetadata();
        $status = ! Settings::isEnabled()
            ? mt('businessprocess', 'Public Health API is globally disabled.')
            : (! $meta->isPublicApiEnabled()
                ? mt('businessprocess', 'Public Health API is disabled for this configuration.')
                : mt('businessprocess', 'Published nodes include expanded Kubernetes dependencies within the selected Public Node Scope.'));
        $this->add(Html::tag('p', null, $status));
        if ($config->hasBeenChanged()) {
            $this->add(Html::tag('p', null, mt('businessprocess', 'Store pending changes to apply these URLs.')));
        }
        $base = 'businessprocess/health/' . PublicHealthService::pathForConfig($config);
        $this->addUrl($config->getTitle(), $base);
        // Materialize parent links before calculating hierarchy-based paths.
        foreach ($config->getBpNodes() as $node) {
            if ($node instanceof BpNode) {
                $node->getChildren();
            }
        }
        $count = 0;
        foreach ($config->getBpNodes() as $node) {
            if (! PublicHealthService::isPublishedNode($node)
                || ($meta->getPublicApiScope() === 'roots' && ! $config->hasRootNode($node->getName()))) {
                continue;
            }
            $this->addUrl($node->getAlias(), $base . '/' . PublicHealthService::pathForNode($config, $node));
            ++$count;
        }
        if ($count === 0) {
            $this->add(Html::tag('p', null, mt('businessprocess', 'No process nodes are published.')));
        }
    }

    private function addUrl(string $label, string $path): void
    {
        $url = $this->healthUrl($path);
        $this->add(Html::tag('div', ['class' => 'health-url-entry'], [
            Html::tag('strong', null, $label),
            Html::tag('input', [
                'type' => 'text', 'readonly' => true, 'value' => $url,
                'aria-label' => mt('businessprocess', 'Health URL'), 'data-health-url' => '1'
            ]),
            Html::tag('button', ['type' => 'button', 'data-copy-health-url' => '1'], mt('businessprocess', 'Copy URL')),
            Html::tag('a', ['href' => $url, 'target' => '_blank', 'rel' => 'noopener'], mt('businessprocess', 'Open'))
        ]));
    }

    protected function healthUrl(string $path): string
    {
        return Url::fromPath($path)->getAbsoluteUrl();
    }
}
