<?php

declare(strict_types=1);

namespace MahakMixin\Client;

use MahakMixin\Http\HttpClient;

final class MixinClient
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {
    }

    public function health(): array { return $this->request('GET', '/api/v4/health/'); }
    public function info(): array { return $this->request('GET', '/api/v4/info/'); }

    /** @param array<string, scalar|null> $query */
    public function categories(array $query = []): array { return $this->request('GET', '/api/v4/categories/', null, $query); }
    /** @param array<string, scalar|null> $query */
    public function products(array $query = []): array { return $this->request('GET', '/api/v4/products/', null, $query); }
    public function product(int $id): array { return $this->request('GET', "/api/v4/products/{$id}/"); }
    /** @param array<string, mixed> $product */
    public function createProduct(array $product): array { return $this->request('POST', '/api/v4/products/', $product); }
    /** @param array<string, mixed> $product */
    public function updateProduct(int $id, array $product): array { return $this->request('PATCH', "/api/v4/products/{$id}/", $product); }
    public function deleteProduct(int $id): array { return $this->request('DELETE', "/api/v4/products/{$id}/"); }
    /** @param array<string, scalar|null> $query */
    public function orders(array $query = []): array { return $this->request('GET', '/api/v4/orders/', null, $query); }
    /** @param array<string, scalar|null> $query */
    public function customers(array $query = []): array { return $this->request('GET', '/api/v4/customers/', null, $query); }

    private function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $response = $this->http->request($method, rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/'), [
            'Authorization' => 'Api-Key ' . $this->apiKey,
        ], $body, $query);

        return is_array($response['data']) ? $response['data'] : ['raw' => $response['data']];
    }
}
