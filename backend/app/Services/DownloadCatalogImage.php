<?php

namespace App\Services;

use CurlHandle;
use Symfony\Component\HttpFoundation\IpUtils;

class DownloadCatalogImage
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** @param resource $destination */
    public function download(string $url, $destination): bool
    {
        $deadline = microtime(true) + 10;

        for ($redirects = 0; $redirects <= 3; $redirects++) {
            $target = $this->publicTarget($url);
            $remainingMs = (int) (($deadline - microtime(true)) * 1000);

            if ($target === null || $remainingMs <= 0 || ! ftruncate($destination, 0) || ! rewind($destination)) {
                return false;
            }

            [$url, $resolve] = $target;
            $received = 0;
            $request = curl_init($url);
            $configured = curl_setopt_array($request, [
                CURLOPT_PROTOCOLS_STR => 'http,https',
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROXY => '',
                CURLOPT_RESOLVE => $resolve,
                CURLOPT_CONNECTTIMEOUT_MS => min(3000, $remainingMs),
                CURLOPT_TIMEOUT_MS => $remainingMs,
                CURLOPT_MAXFILESIZE => self::MAX_BYTES,
                CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => 'ConstructNH catalog image importer',
                CURLOPT_WRITEFUNCTION => static function (CurlHandle $handle, string $chunk) use ($destination, &$received): int {
                    $received += strlen($chunk);

                    return $received <= self::MAX_BYTES ? (int) fwrite($destination, $chunk) : 0;
                },
            ]);

            if (! $configured || curl_exec($request) === false) {
                return false;
            }

            $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);

            if ($status === 200) {
                return $received > 0 && fflush($destination);
            }

            $url = curl_getinfo($request, CURLINFO_REDIRECT_URL);

            if (! in_array($status, [301, 302, 303, 307, 308], true) || ! is_string($url) || $url === '') {
                return false;
            }
        }

        return false;
    }

    /** @return array{string, list<string>}|null */
    private function publicTarget(string $url): ?array
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return null;
        }

        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $port = match ($scheme) {
            'http' => 80,
            'https' => 443,
            default => null,
        };

        if ($port === null || ($parts['port'] ?? $port) !== $port || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $target = "{$scheme}://{$host}{$path}{$query}";
        $ip = str_starts_with($host, '[') && str_ends_with($host, ']') ? substr($host, 1, -1) : $host;

        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicIp($ip) ? [$target, []] : null;
        }

        if (! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || ! preg_match('/\.[a-z][a-z0-9-]*$/D', $host)) {
            return null;
        }

        $records = @dns_get_record($host.'.', DNS_A | DNS_AAAA) ?: [];
        $addresses = [];
        $syntheticAddress = false;

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? '';

            if ($this->isPublicIp($ip)) {
                $addresses[] = str_contains($ip, ':') ? "[{$ip}]" : $ip;
            } elseif (IpUtils::checkIp($ip, '240.0.0.0/4')) {
                $syntheticAddress = true;
            }
        }

        if ($addresses === [] && $syntheticAddress && app()->isLocal()) {
            $addresses = $this->resolvePublicDns($host, 'A') ?: $this->resolvePublicDns($host, 'AAAA');
        }

        // Pin vetted addresses for this hop so cURL cannot resolve the host again.
        return $addresses === [] ? null : [$target, ["{$host}:{$port}:".implode(',', $addresses)]];
    }

    /** @return list<string> */
    private function resolvePublicDns(string $host, string $type): array
    {
        // Local DNS proxies can return synthetic addresses. Ask public DNS for the real address.
        $request = curl_init('https://dns.google/resolve?'.http_build_query(['name' => $host, 'type' => $type]));
        $configured = curl_setopt_array($request, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROTOCOLS_STR => 'https',
            CURLOPT_PROXY => '',
            CURLOPT_RESOLVE => ['dns.google:443:8.8.8.8'],
            CURLOPT_TIMEOUT_MS => 2000,
        ]);
        $body = $configured ? curl_exec($request) : false;
        $response = is_string($body) ? json_decode($body, true) : null;
        $addresses = [];

        foreach ($response['Answer'] ?? [] as $answer) {
            $ip = $answer['data'] ?? '';

            if (is_string($ip) && $this->isPublicIp($ip)) {
                $addresses[] = str_contains($ip, ':') ? "[{$ip}]" : $ip;
            }
        }

        return $addresses;
    }

    private function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }

        if (str_contains($ip, ':')) {
            return IpUtils::checkIp($ip, '2000::/3') && ! IpUtils::checkIp($ip, ['2001::/23', '2002::/16', '3fff::/20']);
        }

        return ! IpUtils::checkIp($ip, '224.0.0.0/3');
    }
}
