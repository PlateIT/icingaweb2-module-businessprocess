<?php

// SPDX-FileCopyrightText: 2020 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\State;

use Icinga\Application\Benchmark;
use Icinga\Application\Logger;
use Icinga\Module\Businessprocess\BpConfig;
use Icinga\Module\Businessprocess\IcingaDbObject;
use Icinga\Module\Businessprocess\ServiceNode;
use Icinga\Module\Icingadb\Common\IcingaRedis;
use Icinga\Module\Icingadb\Model\Host;
use Icinga\Module\Icingadb\Model\Service;
use ipl\Sql\Connection as IcingaDbConnection;
use ipl\Stdlib\Filter;
use Throwable;

class IcingaDbState
{
    /** @var BpConfig */
    protected $config;

    /** @var IcingaDbConnection */
    protected $backend;

    public function __construct(BpConfig $config)
    {
        $this->config = $config;
        $this->backend = IcingaDbObject::fetchDb();
    }

    public static function apply(BpConfig $config)
    {
        if ($config->listInvolvedHostNames() === []) {
            return $config;
        }

        try {
            $self = new static($config);
            $self->retrieveStatesFromBackend();
        } catch (Throwable $error) {
            $config->addError(
                $config->translate('Could not retrieve process state: %s'),
                $error->getMessage()
            );
        }

        return $config;
    }

    public function retrieveStatesFromBackend()
    {
        $config = $this->config;

        try {
            $this->reallyRetrieveStatesFromBackend();
        } catch (Throwable $e) {
            $config->addError(
                $config->translate('Could not retrieve process state: %s'),
                $e->getMessage()
            );
        }
    }

    public function reallyRetrieveStatesFromBackend()
    {
        $config = $this->config;

        $involvedHostNames = $config->listInvolvedHostNames();
        if (empty($involvedHostNames)) {
            return $this;
        }

        Benchmark::measure(sprintf(
            'Retrieving states for business process %s using Icinga DB backend',
            $config->getName()
        ));

        $hosts = Host::on($this->backend)->columns([
            'id' => 'host.id',
            'name' => 'host.name',
            'display_name' => 'host.display_name',
            'hard_state' => 'host.state.hard_state',
            'soft_state' => 'host.state.soft_state',
            'last_state_change' => 'host.state.last_state_change',
            'in_downtime' => 'host.state.in_downtime',
            'is_acknowledged' => 'host.state.is_acknowledged'
        ])->filter(Filter::equal('host.name', $involvedHostNames));

        $services = Service::on($this->backend)->columns([
            'id' => 'service.id',
            'name' => 'service.name',
            'display_name' => 'service.display_name',
            'host_name' => 'host.name',
            'host_display_name' => 'host.display_name',
            'hard_state' => 'service.state.hard_state',
            'soft_state' => 'service.state.soft_state',
            'last_state_change' => 'service.state.last_state_change',
            'in_downtime' => 'service.state.in_downtime',
            'is_acknowledged' => 'service.state.is_acknowledged'
        ])->filter(Filter::equal('host.name', $involvedHostNames));

        // Query and enrich each object exactly once. Imported configurations
        // reuse the same result set instead of multiplying DB/Redis work.
        $serviceResults = $this->fetchRows($services, 'service');
        $hostResults = $this->fetchRows($hosts, 'host');

        foreach ($config->listInvolvedConfigs() as $cfg) {
            foreach ($serviceResults as $row) {
                $this->handleDbRow($row, $cfg, 'service');
            }
            foreach ($hostResults as $row) {
                $this->handleDbRow($row, $cfg, 'host');
            }
        }

        Benchmark::measure('Retrieved states for ' . count($serviceResults) . ' services in ' . $config->getName());
        Benchmark::measure('Retrieved states for ' . count($hostResults) . ' hosts in ' . $config->getName());

        Benchmark::measure('Got states for business process ' . $config->getName());

        return $this;
    }

    private function fetchRows($query, string $type): array
    {
        $ids = [];
        $rows = [];
        foreach ($this->backend->yieldAll($query->assembleSelect()) as $row) {
            $row->hex_id = bin2hex(is_resource($row->id) ? stream_get_contents($row->id) : $row->id);
            $ids[] = $row->hex_id;
            $rows[] = $row;
        }

        if ($ids === []) {
            return [];
        }

        try {
            $fields = ['hard_state', 'soft_state', 'last_state_change', 'in_downtime', 'is_acknowledged'];
            $redisRows = $type === 'service'
                ? iterator_to_array(IcingaRedis::fetchServiceState($ids, $fields))
                : iterator_to_array(IcingaRedis::fetchHostState($ids, $fields));
        } catch (Throwable $error) {
            Logger::warning(
                'Could not enrich Business Process %s states from Icinga Redis (%s): %s',
                $type,
                get_class($error),
                $error->getMessage()
            );
            $redisRows = [];
        }

        foreach ($rows as $index => $row) {
            if (isset($redisRows[$row->hex_id])) {
                $rows[$index] = (object) array_merge((array) $row, $redisRows[$row->hex_id]);
            }
        }

        return $rows;
    }

    protected function handleDbRow($row, BpConfig $config, $type)
    {
        if ($type === 'service') {
            $key = BpConfig::joinNodeName($row->host_name, $row->name);
        } else {
            $key = BpConfig::joinNodeName($row->name, 'Hoststatus');
        }

        // We fetch more states than we need, so skip unknown ones
        if (! $config->hasNode($key)) {
            return;
        }

        $node = $config->getNode($key);

        if ($this->config->usesHardStates()) {
            if ($row->hard_state !== null) {
                $node->setState($row->hard_state)->setMissing(false);
            }
        } else {
            if ($row->soft_state !== null) {
                $node->setState($row->soft_state)->setMissing(false);
            }
        }

        if ($row->last_state_change !== null) {
            $node->setLastStateChange($row->last_state_change / 1000.0);
        }

        $node->setDowntime($this->toBoolean($row->in_downtime));
        $node->setAck($this->toBoolean($row->is_acknowledged));
        $node->setAlias($row->display_name);

        if ($node instanceof ServiceNode) {
            $node->setHostAlias($row->host_display_name);
        }
    }

    private function toBoolean($value): bool
    {
        switch (true) {
            // Icinga Redis >= 6 (is_acknowledged, in_downtime)
            case $value === true:
            case $value === false:
                return $value;
            // Icinga Redis < 6 (is_acknowledged)
            case $value === 1:
            case $value === 2:
                return true;
            case $value === 0:
                return false;
            // Icinga DB < 1.4 (is_acknowledged)
            case $value === 'sticky':
                return true;
            // Icinga DB >= * (is_acknowledged, in_downtime)
            case $value === 'y':
                return true;
            case $value === 'n':
                return false;
        }

        return false;
    }
}
