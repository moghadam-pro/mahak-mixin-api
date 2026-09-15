<?php

declare(strict_types=1);

namespace MahakMixin\Http;

use JsonException;

final class HttpClient
{
    public function __construct(
        private readonly int $timeoutSeconds = 30,
        private readonly bool $verifyTls = true,
    ) {
    }

    /** @param array<string, string> $headers @param array<string, scalar|null> $query */
    public function request(string $method, string $url, array $headers = [], ?array $json = null, array $query = []): array
    {
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw new HttpException('Unable to initialize cURL');
        }

        $headerLines = ['Accept: application/json'];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            CURLOPT_USERAGENT => 'mahak-mixin-bridge/1.0',
        ];

        if ($json !== null) {
            try {
                $payload = json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $exception) {
                throw new HttpException('Unable to encode request JSON: ' . $exception->getMessage());
            }
            $options[CURLOPT_POSTFIELDS] = $payload;
            $headerLines[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $headerLines;
        }

        curl_setopt_array($curl, $options);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($raw === false) {
            throw new HttpException('Network request failed: ' . $error);
        }

        $decoded = null;
        if ($raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $decoded = $raw;
            }
        }

        if ($status < 200 || $status >= 300) {
            throw new HttpException("HTTP {$status} returned by {$url}", $status, $decoded);
        }

        return ['status' => $status, 'data' => $decoded];
    }
}
