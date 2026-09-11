<?php

// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Businessprocess\Kubernetes;

use Icinga\Application\Config;
use RuntimeException;

class ApiClient
{
    private const MAX_RESPONSE_BYTES = 32 * 1024 * 1024;

    private string $url;
    private string $token;
    private int $timeout;

    public function __construct(?string $token = null)
    {
        $section = Config::module('businessprocess')->getSection('kubernetes');
        $this->url = rtrim(getenv('ICINGA_KUBERNETES_API_URL') ?: (string) $section->get('api_url', 'http://icinga-kubernetes-api:8080'), '/');
        $this->timeout = (int) $section->get('api_timeout', 15);
        $parts = parse_url($this->url);
        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new RuntimeException('Invalid Icinga Kubernetes API URL');
        }
        if ($this->timeout < 1 || $this->timeout > 60) {
            throw new RuntimeException('Icinga Kubernetes API timeout must be between 1 and 60 seconds');
        }
        $tokenFile = getenv('ICINGA_KUBERNETES_API_ADMIN_TOKEN_FILE') ?: $section->get('api_token_file');
        if ($token === null && $tokenFile) {
            $token = @file_get_contents($tokenFile);
            if ($token === false) {
                throw new RuntimeException(sprintf('Cannot read Kubernetes API token file %s', $tokenFile));
            }
        }
        $this->token = trim($token ?? '');
        if ($this->token === '') {
            throw new RuntimeException('No Kubernetes API token configured for Business Process');
        }
    }

    public function get(string $path, array $query = []): array
    {
        $url = $this->url . '/api/v1/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query(array_filter($query, static fn($v) => $v !== null && $v !== ''));
        }
        return $this->request('GET', $url);
    }

    public function post(string $path, array $body): array
    {
        return $this->request('POST', $this->url . '/api/v1/' . ltrim($path, '/'), $body);
    }

    public function put(string $path, array $body): array
    {
        return $this->request('PUT', $this->url . '/api/v1/' . ltrim($path, '/'), $body);
    }

    public function delete(string $path, array $query = []): bool
    {
        $url = $this->url . '/api/v1/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        $this->request('DELETE', $url);
        return true;
    }

    private function request(string $method, string $url, ?array $body = null): array
    {
        if (! function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required by Business Process');
        }
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $this->token];
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Cannot initialize Icinga Kubernetes API request');
        }
        $response = '';
        $tooLarge = false;
        curl_setopt_array($ch, [
            CURLOPT_NOPROXY => '*',
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response, &$tooLarge): int {
                if (strlen($response) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            }
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
            curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ['Content-Type: application/json']));
        }
        $successful = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($status === 404) {
            throw new ApiNotFoundException('API object not found');
        }
        if ($status === 409) {
            throw new ApiConflictException('API object was modified concurrently');
        }
        if ($tooLarge) {
            throw new RuntimeException('Icinga Kubernetes API response exceeds 32 MiB');
        }
        if ($successful === false || $status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('Icinga Kubernetes API request failed with HTTP %d', $status));
        }
        if ($response === '' || $status === 204) {
            return [];
        }
        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Icinga Kubernetes API returned a non-object response');
        }
        return $decoded;
    }
}

class ApiNotFoundException extends RuntimeException
{
}

class ApiConflictException extends RuntimeException
{
}
