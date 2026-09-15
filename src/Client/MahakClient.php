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
        private readonly int $databaseId,
        private readonly ?string $packageNo = null,
    ) {
    }

    public function login(): array
    {
        $body = [
            'userName' => $this->username,
            'password' => $this->password,
            'databaseId' => $this->databaseId,
            'language' => 'fa',
            'description' => 'Mahak-Mixin synchronization bridge',
            'clientVersion' => '1.0.0',
        ];
        if ($this->packageNo !== null && $this->packageNo !== '') {
            $body['packageNo'] = $this->packageNo;
        }

        $response = $this->http->request('POST', $this->url('/Sync/LoginV2'), [], $body)['data'];
        if (!is_array($response) || !$this->read($response, 'Result', false)) {
            throw new RuntimeException('Mahak login failed');
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
        return $this->authenticatedPost('/Sync/GetAllDataV2', $request);
    }

    /** @param array<string, mixed> $objects */
    public function saveAllData(array $objects): array
    {
        return $this->authenticatedPost('/Sync/SaveAllDataV2', $objects);
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
}
