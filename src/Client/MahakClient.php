<?php

declare(strict_types=1);

namespace MahakMixin\Client;

use MahakMixin\Http\HttpClient;
use RuntimeException;

final class MahakClient
{
    private ?string $token = null;

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $password,
        private readonly string $loginPath = '/Sync/Login',
        private readonly string $getAllDataPath = '/Sync/GetAllData',
        private readonly string $saveAllDataPath = '/Sync/SaveAllData',
    ) {
    }

    public function login(): array
    {
        $body = [
            'userName' => $this->username,
            'password' => $this->password,
        ];

        $response = $this->http->request('POST', $this->url($this->loginPath), [], $body)['data'];
        if (!is_array($response)) {
            throw new RuntimeException('Mahak login returned a non-JSON response');
        }
        if (!$this->read($response, 'Result', false)) {
            throw new RuntimeException($this->loginError($response));
        }

        $data = $this->read($response, 'Data', []);
        $token = is_array($data) ? $this->read($data, 'UserToken') : null;
        if (!is_string($token) || $token === '') {
            throw new RuntimeException('Mahak response does not contain UserToken');
        }
        $this->token = $token;

        return $response;
    }

    /** @param array<string, mixed> $request */
    public function getAllData(array $request): array
    {
        return $this->authenticatedPost($this->getAllDataPath, $request);
    }

    /** @param array<string, mixed> $objects */
    public function saveAllData(array $objects): array
    {
        return $this->authenticatedPost($this->saveAllDataPath, $objects);
    }

    private function authenticatedPost(string $path, array $body): array
    {
        if ($this->token === null) {
            $this->login();
        }

        $response = $this->http->request('POST', $this->url($path), [
            'Authorization' => 'Bearer ' . $this->token,
        ], $body)['data'];

        if (!is_array($response)) {
            throw new RuntimeException('Mahak returned a non-JSON response');
        }
        $result = $this->read($response, 'Result');
        if ($result === false) {
            throw new RuntimeException($this->apiError($path, $response));
        }

        return $response;
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }

    private function read(array $data, string $key, mixed $default = null): mixed
    {
        foreach ($data as $candidate => $value) {
            if (strcasecmp((string) $candidate, $key) === 0) {
                return $value;
            }
        }

        return $default;
    }

    private function loginError(array $response): string
    {
        $parts = [];
        $message = $this->read($response, 'Message');
        $code = $this->read($response, 'Code');
        $data = $this->read($response, 'Data', []);
        if (is_array($data)) {
            $message ??= $this->read($data, 'ErrorMessage');
            $code ??= $this->read($data, 'ErrorCode');
        }
        if (is_scalar($code) && (string) $code !== '' && (string) $code !== '0') {
            $parts[] = 'code=' . (string) $code;
        }
        if (is_string($message) && trim($message) !== '') {
            $parts[] = trim($message);
        }

        return 'Mahak login failed' . ($parts === [] ? '' : ': ' . implode(' - ', $parts));
    }

    private function apiError(string $path, array $response): string
    {
        $parts = [];
        $code = $this->read($response, 'Code');
        $message = $this->read($response, 'Message');
        if (is_scalar($code) && (string) $code !== '') {
            $parts[] = 'code=' . (string) $code;
        }
        if (is_string($message) && trim($message) !== '') {
            $parts[] = trim($message);
        }
        return "Mahak {$path} failed" . ($parts === [] ? '' : ': ' . implode(' - ', $parts));
    }
}
