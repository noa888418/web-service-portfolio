<?php

namespace Tests\Support;

final class CookieBrowser
{
    public array $cookies = [];

    public function __construct(public string $base) {}

    public function request(string $method, string $path, ?array $data = null, bool $csrf = true, array $headers = []): array
    {
        return $this->raw($method, $path, $data === null ? null : json_encode((object) $data, JSON_THROW_ON_ERROR), $csrf, $headers);
    }

    public function raw(string $method, string $path, ?string $body, bool $csrf = true, array $headers = []): array
    {
        $handle = $this->handle($method, $path, $body, $csrf, $headers);
        $raw = curl_exec($handle);
        if ($raw === false) {
            throw new \RuntimeException('HTTP test client failed: '.curl_errno($handle));
        }

        return $this->finish($handle, $raw);
    }

    public function handle(string $method, string $path, ?string $body, bool $csrf = true, array $headers = []): \CurlHandle
    {
        $defaults = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];
        if ($csrf && isset($this->cookies['XSRF-TOKEN'])) {
            $defaults['X-XSRF-TOKEN'] = rawurldecode($this->cookies['XSRF-TOKEN']);
        }
        $wireHeaders = [];
        foreach (array_replace($defaults, $headers) as $key => $value) {
            $wireHeaders[] = $key.': '.$value;
        }
        $handle = curl_init($this->base.$path);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $wireHeaders,
            CURLOPT_COOKIE => implode('; ', array_map(fn ($key, $value) => $key.'='.$value, array_keys($this->cookies), $this->cookies)),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        return $handle;
    }

    public function finish(\CurlHandle $handle, string $raw): array
    {
        $size = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $headers = [];
        foreach (explode("\r\n", substr($raw, 0, $size)) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $headers[strtolower($key)][] = trim($value);
            if (strtolower($key) === 'set-cookie') {
                [$name, $cookie] = explode('=', explode(';', trim($value), 2)[0], 2);
                $this->cookies[$name] = $cookie;
            }
        }
        $body = substr($raw, $size);

        return ['status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'headers' => $headers,
            'body' => $body, 'json' => $body === '' ? null : json_decode($body, true)];
    }
}
