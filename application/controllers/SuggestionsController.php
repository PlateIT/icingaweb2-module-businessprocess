<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\Controllers;

use Exception;
use Icinga\Module\Businessprocess\BpConfig;
use Icinga\Module\Businessprocess\BpNode;
use Icinga\Module\Businessprocess\HostNode;
use Icinga\Module\Businessprocess\IcingaDbObject;
use Icinga\Module\Businessprocess\Kubernetes\Feature as KubernetesFeature;
use Icinga\Module\Businessprocess\Kubernetes\Kind as KubernetesKind;
use Icinga\Module\Businessprocess\Kubernetes\NodeName as KubernetesNodeName;
use Icinga\Module\Businessprocess\Kubernetes\ObjectRepository as KubernetesObjectRepository;
use Icinga\Module\Businessprocess\ImportedNode;
use Icinga\Module\Businessprocess\ServiceNode;
use Icinga\Module\Businessprocess\Web\Controller;
use Icinga\Module\Icingadb\Model\Host;
use Icinga\Module\Icingadb\Model\Service;
use ipl\Stdlib\Filter;
use ipl\Web\FormElement\TermInput\TermSuggestions;

class SuggestionsController extends Controller
{
    public function kubernetesObjectAction()
    {
        $kind = KubernetesKind::canonicalize($this->params->get('kind', 'namespace'));
        $apiKind = trim((string) $this->params->get('apiKind', $kind));
        $group = trim((string) $this->params->get('group', ''));
        $version = trim((string) $this->params->get('version', ''));
        $cluster = trim((string) $this->params->get('cluster', ''));
        $namespace = trim((string) $this->params->get('namespace', ''));
        $suggestions = new TermSuggestions((function () use ($kind, $apiKind, $group, $version, $cluster, $namespace, &$suggestions) {
            if (! KubernetesFeature::isAvailable()
                || $apiKind === ''
                || strlen($apiKind) > 512
                || strlen($group) > 512
                || $version === ''
                || strlen($version) > 512
                || ($namespace !== '' && (strlen($namespace) > 63
                    || ! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $namespace)))
            ) {
                return;
            }

            $page = KubernetesObjectRepository::search(
                $apiKind,
                $suggestions->getSearchTerm(),
                50,
                $cluster === '' ? null : $cluster,
                $group,
                $version,
                null,
                $namespace === '' ? null : $namespace
            );
            foreach ($page['items'] as $object) {
                $uuid = KubernetesObjectRepository::uuidToString($object->uuid);
                $label = implode(' / ', KubernetesObjectRepository::labelParts($kind, $object));

                yield [
                    'search' => KubernetesNodeName::create($kind, $uuid),
                    'label'  => $label,
                    'class'  => 'kubernetes-' . $kind,
                    'kind'   => KubernetesKind::title($kind)
                ];
            }
        })());

        $suggestions->setGroupingCallback(function (array $data) {
            return $data['kind'];
        });

        $this->getDocument()->addHtml($suggestions->forRequest($this->getServerRequest()));
    }
    public function processAction()
    {
        $ignoreList = [];
        $forConfig = null;
        $forParent = null;
        if ($this->params->has('config')) {
            $forConfig = $this->loadModifiedBpConfig();

            $parentName = $this->params->get('node');
            if ($parentName) {
                $forParent = $forConfig->getBpNode($parentName);

                $collectParents = function ($node) use (&$ignoreList, &$collectParents) {
                    foreach ($node->getParents() as $parent) {
                        $ignoreList[$parent->getName()] = true;

                        if ($parent->hasParents()) {
                            $collectParents($parent);
                        }
                    }
                };

                $ignoreList[$parentName] = true;
                if ($forParent->hasParents()) {
                    $collectParents($forParent);
                }

                foreach ($forParent->getChildNames() as $name) {
                    $ignoreList[$name] = true;
                }
            }
        }

        $suggestions = new TermSuggestions((function () use ($forConfig, $forParent, $ignoreList, &$suggestions) {
            foreach ($this->storage()->listProcessNames() as $config) {
                $differentConfig = false;
                if ($forConfig === null || $config !== $forConfig->getName()) {
                    if ($forConfig !== null && $forParent === null) {
                        continue;
                    }

                    try {
                        $bp = $this->storage()->loadProcess($config);
                    } catch (Exception $_) {
                        continue;
                    }

                    $differentConfig = true;
                } else {
                    $bp = $forConfig;
                }

                foreach ($bp->getBpNodes() as $bpNode) {
                    /** @var BpNode $bpNode */
                    if ($bpNode instanceof ImportedNode) {
                        continue;
                    }

                    $search = $bpNode->getName();
                    if ($differentConfig) {
                        $search = "@$config:$search";
                    }

                    if (
                        in_array($search, $suggestions->getExcludeTerms(), true)
                        || isset($ignoreList[$search])
                        || ($forParent
                            ? $forParent->hasChild($search)
                            : ($forConfig && $forConfig->hasRootNode($search))
                        )
                    ) {
                        continue;
                    }

                    if (
                        $suggestions->matchSearch($bpNode->getName())
                        || (! $bpNode->hasAlias() || $suggestions->matchSearch($bpNode->getAlias()))
                        || $bpNode->getName() === $suggestions->getOriginalSearchValue()
                        || $bpNode->getAlias() === $suggestions->getOriginalSearchValue()
                    ) {
                        yield [
                            'search' => $search,
                            'label'  => $bpNode->getLabel(),
                            'config' => $config
                        ];
                    }
                }
            }
        })());
        $suggestions->setGroupingCallback(function (array $data) {
            return $this->storage()->loadMetadata($data['config'])->getTitle();
        });

        $this->getDocument()->addHtml($suggestions->forRequest($this->getServerRequest()));
    }

    public function icingadbHostAction()
    {
        $excludes = Filter::none();
        $forConfig = null;
        if ($this->params->has('config')) {
            $forConfig = $this->loadModifiedBpConfig();

            if ($this->params->has('node')) {
                $nodeName = $this->params->get('node');
                $node = $forConfig->getBpNode($nodeName);

                foreach ($node->getChildren() as $child) {
                    if ($child instanceof HostNode) {
                        $excludes->add(Filter::equal('host.name', $child->getHostname()));
                    }
                }
            }
        }

        $suggestions = new TermSuggestions((function () use ($forConfig, $excludes, &$suggestions) {
            foreach ($suggestions->getExcludeTerms() as $excludeTerm) {
                [$hostName, $_] = BpConfig::splitNodeName($excludeTerm);
                $excludes->add(Filter::equal('host.name', $hostName));
            }

            $hosts = Host::on(IcingaDbObject::fetchDb())
                ->columns(['host.name', 'host.display_name'])
                ->limit(50);
            IcingaDbObject::applyIcingaDbRestrictions($hosts);
            $hosts->filter(Filter::all(
                $excludes,
                Filter::any(
                    Filter::like('host.name', $suggestions->getSearchTerm()),
                    Filter::equal('host.name', $suggestions->getOriginalSearchValue()),
                    Filter::like('host.display_name', $suggestions->getSearchTerm()),
                    Filter::equal('host.display_name', $suggestions->getOriginalSearchValue()),
                    Filter::like('host.address', $suggestions->getSearchTerm()),
                    Filter::equal('host.address', $suggestions->getOriginalSearchValue()),
                    Filter::like('host.address6', $suggestions->getSearchTerm()),
                    Filter::equal('host.address6', $suggestions->getOriginalSearchValue()),
                    Filter::like('host.customvar_flat.flatvalue', $suggestions->getSearchTerm()),
                    Filter::equal('host.customvar_flat.flatvalue', $suggestions->getOriginalSearchValue()),
                    Filter::like('hostgroup.name', $suggestions->getSearchTerm()),
                    Filter::equal('hostgroup.name', $suggestions->getOriginalSearchValue())
                )
            ));
            foreach ($hosts as $host) {
                yield [
                    'search' => BpConfig::joinNodeName($host->name, 'Hoststatus'),
                    'label'  => $host->display_name,
                    'class'  => 'host'
                ];
            }
        })());

        $this->getDocument()->addHtml($suggestions->forRequest($this->getServerRequest()));
    }

    public function icingadbServiceAction()
    {
        $excludes = Filter::none();
        $forConfig = null;
        if ($this->params->has('config')) {
            $forConfig = $this->loadModifiedBpConfig();

            if ($this->params->has('node')) {
                $nodeName = $this->params->get('node');
                $node = $forConfig->getBpNode($nodeName);

                foreach ($node->getChildren() as $child) {
                    if ($child instanceof ServiceNode) {
                        $excludes->add(Filter::all(
                            Filter::equal('host.name', $child->getHostname()),
                            Filter::equal('service.name', $child->getServiceDescription())
                        ));
                    }
                }
            }
        }

        $suggestions = new TermSuggestions((function () use ($forConfig, $excludes, &$suggestions) {
            foreach ($suggestions->getExcludeTerms() as $excludeTerm) {
                [$hostName, $serviceName] = BpConfig::splitNodeName($excludeTerm);
                if ($serviceName !== null && $serviceName !== 'Hoststatus') {
                    $excludes->add(Filter::all(
                        Filter::equal('host.name', $hostName),
                        Filter::equal('service.name', $serviceName)
                    ));
                }
            }

            $services = Service::on(IcingaDbObject::fetchDb())
                ->columns(['host.name', 'host.display_name', 'service.name', 'service.display_name'])
                ->limit(50);
            IcingaDbObject::applyIcingaDbRestrictions($services);
            $services->filter(Filter::all(
                $excludes,
                Filter::any(
                    Filter::like('host.name', $suggestions->getSearchTerm()),
                    Filter::equal('host.name', $suggestions->getOriginalSearchValue()),
                    Filter::like('host.display_name', $suggestions->getSearchTerm()),
                    Filter::equal('host.display_name', $suggestions->getOriginalSearchValue()),
                    Filter::like('service.name', $suggestions->getSearchTerm()),
                    Filter::equal('service.name', $suggestions->getOriginalSearchValue()),
                    Filter::like('service.display_name', $suggestions->getSearchTerm()),
                    Filter::equal('service.display_name', $suggestions->getOriginalSearchValue()),
                    Filter::like('service.customvar_flat.flatvalue', $suggestions->getSearchTerm()),
                    Filter::equal('service.customvar_flat.flatvalue', $suggestions->getOriginalSearchValue()),
                    Filter::like('servicegroup.name', $suggestions->getSearchTerm()),
                    Filter::equal('servicegroup.name', $suggestions->getOriginalSearchValue())
                )
            ));
            foreach ($services as $service) {
                yield [
                    'class'  => 'service',
                    'search' => BpConfig::joinNodeName($service->host->name, $service->name),
                    'label'  => sprintf(
                        $this->translate('%s on %s', '<service> on <host>'),
                        $service->display_name,
                        $service->host->display_name
                    )
                ];
            }
        })());

        $this->getDocument()->addHtml($suggestions->forRequest($this->getServerRequest()));
    }

}
