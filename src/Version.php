<?php

declare(strict_types=1);

namespace MahakMixin;

final class Version
{
    private const FALLBACK = 'dev';

    public static function current(): string
    {
        $contents = @file_get_contents(dirname(__DIR__) . '/VERSION');
        if ($contents === false) {
            return self::FALLBACK;
        }

        $version = trim($contents);

        return preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $version) === 1
            ? $version
            : self::FALLBACK;
    }
}
