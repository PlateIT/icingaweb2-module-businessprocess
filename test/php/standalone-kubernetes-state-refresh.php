<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Icinga\Application {
    class Config
    {
        public static function module(string $name): \BpStateTestConfig
        {
            return new \BpStateTestConfig();
        }
    }
}

namespace Icinga\Exception {
    class ConfigurationError extends \RuntimeException {}
}

namespace Icinga\Module\Businessprocess {
    function mt(string $domain, string $message): string { return $message; }
}

namespace {
    final class BpStateTestSection
    {
        public function get(string $key, mixed $default = null): mixed
        {
            return match ($key) {
                'enabled' => 'yes',
                'api_url' => getenv('ICINGA_KUBERNETES_API_URL'),
                'api_timeout' => 5,
                'api_token_file' => getenv('ICINGA_KUBERNETES_API_ADMIN_TOKEN_FILE'),
                default => $default
            };
        }
    }

    final class BpStateTestConfig
    {
        public function hasSection(string $name): bool { return $name === 'kubernetes'; }
        public function getSection(string $name): BpStateTestSection { return new BpStateTestSection(); }
    }

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
    use Icinga\Module\Businessprocess\KubernetesNode;
    use Icinga\Module\Businessprocess\State\KubernetesState;

    function writeSourceState(string $path, string $state, string $freshness, int $version, bool $fail = false): void
    {
        file_put_contents($path, json_encode(compact('state', 'freshness', 'version', 'fail'), JSON_THROW_ON_ERROR));
    }

    function assertNodeState(KubernetesNode $node, int $expected, string $message): void
    {
        if ($node->getState() !== $expected) {
            throw new RuntimeException($message . ': got ' . $node->getState() . ', want ' . $expected);
        }
    }

    $stateFile = $argv[1] ?? throw new RuntimeException('state-file argument is required');
    $config = new BpConfig('state-refresh');
    $node = $config->createKubernetesNode('pod', '10000000-0000-4000-8000-000000000000')
        ->setExpandDependencies(false);

    writeSourceState($stateFile, 'ok', 'live', 1);
    KubernetesState::apply($config);
    if ($config->getErrors() !== []) {
        throw new RuntimeException('initial calculation errors: ' . implode('; ', $config->getErrors()));
    }
    assertNodeState($node, KubernetesNode::ICINGA_OK, 'initial live state');

    writeSourceState($stateFile, 'critical', 'live', 2);
    KubernetesState::apply($config);
    assertNodeState($node, KubernetesNode::ICINGA_CRITICAL, 'new calculation reused the previous green cache');

    writeSourceState($stateFile, 'ok', 'stale', 3);
    KubernetesState::apply($config);
    assertNodeState($node, KubernetesNode::ICINGA_UNKNOWN, 'stale source was reported as healthy');

    writeSourceState($stateFile, 'ok', 'live', 4, true);
    KubernetesState::apply($config);
    assertNodeState($node, KubernetesNode::ICINGA_UNKNOWN, 'source outage retained a previous state');

    for ($version = 5; $version < 205; $version++) {
        $state = $version % 2 === 0 ? 'warning' : 'ok';
        writeSourceState($stateFile, $state, 'live', $version);
        KubernetesState::apply($config);
        assertNodeState(
            $node,
            $state === 'warning' ? KubernetesNode::ICINGA_WARNING : KubernetesNode::ICINGA_OK,
            'state refresh failed under repeated calculations'
        );
    }

    echo "Kubernetes Business Process state refresh/load/outage test passed.\n";
}
