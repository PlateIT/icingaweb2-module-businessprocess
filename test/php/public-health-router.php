<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'Icinga\\Module\\Businessprocess\\';
    if (str_starts_with($class, $prefix)) {
        $path = $root . '/library/Businessprocess/'
            . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

use Icinga\Module\Businessprocess\BpConfig;
use Icinga\Module\Businessprocess\Node;
use Icinga\Module\Businessprocess\PublicHealth\HttpContract;
use Icinga\Module\Businessprocess\PublicHealth\PublicHealthService;
use Icinga\Module\Businessprocess\PublicHealth\StatusMapper;
use Icinga\Module\Businessprocess\Storage\Storage;

if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/health') {
    http_response_code(404);
    exit;
}
$delayMs = filter_var(getenv('BP_HEALTH_DELAY_MS') ?: '0', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 0, 'max_range' => 250]
]);
if ($delayMs === false) {
    http_response_code(500);
    exit;
}
if ($delayMs > 0) {
    usleep($delayMs * 1000);
}

$method = $_SERVER['REQUEST_METHOD'];
if (! HttpContract::allows($method)) {
    header('Allow: ' . HttpContract::ALLOW);
    $payload = ['status' => StatusMapper::UNKNOWN];
    $status = 405;
} else {
    $config = new BpConfig('public-health-test');
    $config->getMetadata()->set('Title', 'Public Health Test');
    $config->getMetadata()->set('PublicApi', 'yes');
    $config->getMetadata()->set('PublicApiScope', 'published');
    $config->getMetadata()->set('PublicApiRelations', 'none');
    $config->createBp('service')->setPublicStatus(true)->setState(Node::ICINGA_OK);
    $config->addRootNode('service');
    $outageFile = getenv('BP_HEALTH_OUTAGE_FILE');
    $storage = new class ($config, $outageFile) extends Storage {
        public function __construct(private BpConfig $testConfig, private string $outageFile) {}
        private function available(): void
        {
            if ($this->outageFile !== '' && is_file($this->outageFile)) {
                throw new RuntimeException('simulated backend credential and endpoint details');
            }
        }
        public function listProcesses() { $this->available(); return [$this->testConfig->getName()]; }
        public function listProcessNames() { return $this->listProcesses(); }
        public function listAllProcessNames() { return $this->listProcesses(); }
        public function hasProcess($name) { $this->available(); return $name === $this->testConfig->getName(); }
        public function loadProcess($name) { $this->available(); return $this->testConfig; }
        public function storeProcess(BpConfig $config) { throw new RuntimeException('read only'); }
        public function deleteProcess($name) { throw new RuntimeException('read only'); }
        public function loadMetadata($name) { $this->available(); return $this->testConfig->getMetadata(); }
    };

    try {
        $payload = (new PublicHealthService($storage, static function (): void {}))->catalog();
        $status = StatusMapper::httpStatus($payload['status']);
    } catch (Throwable $_) {
        $payload = ['status' => StatusMapper::UNKNOWN];
        $status = 503;
    }
}

http_response_code($status);
foreach (HttpContract::headers() as $name => $value) {
    header($name . ': ' . $value);
}
if (HttpContract::hasBody($method)) {
    echo HttpContract::encode($payload);
}
