<?php

declare(strict_types=1);

namespace MahakMixin\Sync;

use MahakMixin\Client\MahakClient;
use MahakMixin\Persistence\StateStore;
use RuntimeException;

final class OrderCustomerResolver
{
    public function __construct(private readonly MahakClient $mahak, private readonly StateStore $state, private readonly int $visitorId) {}

    public static function normalizePhone(string $phone): string
    {
        $phone = strtr($phone, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);
        $phone = preg_replace('/\D/', '', $phone) ?? '';
        if (str_starts_with($phone, '0098')) $phone = '0' . substr($phone, 4);
        elseif (str_starts_with($phone, '98') && strlen($phone) === 12) $phone = '0' . substr($phone, 2);
        return $phone;
    }

    /** Maps only fields exposed by the Mixin order schema; shipping recipient stays in invoice address. */
    public static function person(array $order): array
    {
        $name = trim((string) ($order['customer_name'] ?? ''));
        $phone = self::normalizePhone((string) ($order['customer_phone'] ?? ''));
        $email = trim((string) ($order['customer_email'] ?? ''));
        $customerId = (string) ($order['customer_id'] ?? '');
        if ($name === '' || ($phone === '' && $email === '' && ($customerId === '' || $customerId === '0'))) {
            throw new RuntimeException('Order customer requires a name and customer id, phone or email');
        }
        $identity = $phone !== '' ? 'phone:' . $phone : ($customerId !== '' && $customerId !== '0' ? 'customer:' . $customerId : 'email:' . strtolower($email));
        $key = hash('sha256', $identity);
        $clientId = 8200000000000000 + (int) hexdec(substr($key, 0, 12));
        $parts = explode(' ', $name, 2);
        return [
            'key' => $key,
            'person' => [
                'personClientId' => $clientId, 'personType' => 0,
                'firstName' => count($parts) > 1 ? $parts[0] : '',
                'lastName' => count($parts) > 1 ? $parts[1] : $name,
                'mobile' => $phone, 'phone' => $phone, 'email' => $email,
                'state' => (string) ($order['shipping_province'] ?? ''),
                'city' => (string) ($order['shipping_city'] ?? ''),
                'address' => (string) ($order['shipping_address'] ?? ''),
                'postalCode' => (string) ($order['shipping_zip_code'] ?? ''),
                'description' => 'Mixin customer ' . $customerId,
                'deleted' => false,
            ],
        ];
    }

    public function resolve(array $order): array
    {
        $result = self::person($order);
        $mapped = $this->state->mapping('customer', $result['key']);
        if ($mapped !== null) {
            return $result + ['reference' => ['personId' => (int) $mapped], 'create' => false];
        }
        $version = 0;
        $matches = [];
        for ($page = 0; $page < 100; $page++) {
            $response = $this->mahak->getAllData(['currentVisitorId' => $this->visitorId, 'fromPersonVersion' => $version, 'pageSize' => 5000]);
            $data = self::value($response, 'Data', []);
            $objects = self::value($data, 'Objects', $data);
            $people = self::value($objects, 'People', []);
            if (!is_array($people)) throw new RuntimeException('Mahak People response is invalid');
            $next = $version;
            foreach ($people as $person) {
                if (!is_array($person)) continue;
                $next = max($next, (int) self::value($person, 'rowVersion', 0));
                if ((bool) self::value($person, 'deleted', false)) continue;
                $sameClient = (string) self::value($person, 'personClientId') === (string) $result['person']['personClientId'];
                $samePhone = $result['person']['mobile'] !== '' && self::normalizePhone((string) self::value($person, 'mobile', '')) === $result['person']['mobile'];
                if ($sameClient || $samePhone) $matches[(int) self::value($person, 'personId', 0)] = true;
            }
            if (count($people) < 5000) break;
            if ($next <= $version || $page === 99) throw new RuntimeException('Mahak People paging did not complete; customer creation blocked');
            $version = $next;
        }
        unset($matches[0]);
        if (count($matches) > 1) throw new RuntimeException('Multiple Mahak customers match this phone; resolve duplicate customer records first');
        if ($matches !== []) {
            $id = (int) array_key_first($matches);
            return $result + ['reference' => ['personId' => $id], 'create' => false];
        }
        return $result + ['reference' => ['personClientId' => $result['person']['personClientId']], 'create' => true];
    }

    public static function value(mixed $data, string $key, mixed $default = null): mixed
    {
        if (!is_array($data)) return $default;
        foreach ($data as $candidate => $value) if (strcasecmp((string) $candidate, $key) === 0) return $value;
        return $default;
    }
}
