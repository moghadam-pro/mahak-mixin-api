<?php

declare(strict_types=1);

namespace MahakMixin;

use InvalidArgumentException;

final class Config
{
    public static function loadEnvFile(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = array_map('trim', explode('=', $line, 2));
            if ($name === '' || getenv($name) !== false) {
                continue;
            }

            if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                $value = substr($value, 1, -1);
            }
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }

    public static function string(string $name, ?string $default = null): string
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            if ($default !== null) {
                return $default;
            }
            throw new InvalidArgumentException("Missing required environment variable: {$name}");
        }

        return trim($value);
    }

    public static function int(string $name, ?int $default = null): int
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            if ($default !== null) {
                return $default;
            }
            throw new InvalidArgumentException("Missing required environment variable: {$name}");
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException("Environment variable {$name} must be an integer");
        }

        return (int) $value;
    }

    public static function bool(string $name, bool $default): bool
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            return $default;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new InvalidArgumentException("Environment variable {$name} must be true or false");
        }

        return $parsed;
    }

    /** @return array<string,int> */
    public static function intMap(string $name, array $default = []): array
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            return $default;
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException("Environment variable {$name} must be valid JSON: {$exception->getMessage()}");
        }
        if (!is_array($decoded)) {
            throw new InvalidArgumentException("Environment variable {$name} must be a JSON object");
        }
        $map = [];
        foreach ($decoded as $sourceId => $targetId) {
            if (!is_scalar($sourceId) || filter_var($targetId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new InvalidArgumentException("Environment variable {$name} must map IDs to positive integers");
            }
            $map[(string) $sourceId] = (int) $targetId;
        }
        return $map;
    }

    public static function path(string $name, string $default): string
    {
        $path = self::string($name, $default);
        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $path) === 1) {
            return $path;
        }

        return dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $path), '/');
    }
}
