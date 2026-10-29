<?php

declare(strict_types=1);

namespace Astral\Twide\Extension;

use Astral\Twide\Contracts\ExtensionInterface;
use Astral\Utilities\Arr;
use Astral\Utilities\Date;
use Astral\Utilities\Str;
use InvalidArgumentException;

/**
 * Official bridge from Twide to astral-php/astral-utilities.
 *
 * The package is a normal runtime dependency, but the extension is opt-in:
 * applications decide whether these names should be exposed to templates.
 */
final class UtilitiesExtension implements ExtensionInterface
{
    public function getFunctions(): array
    {
        return [
            'str_slug' => static fn (mixed $value, string $separator = '-'): string =>
                Str::slug(self::stringValue($value, 'str_slug'), $separator),
            'str_truncate' => static fn (mixed $value, int $limit = 100, string $end = '…'): string =>
                Str::truncate(self::stringValue($value, 'str_truncate'), $limit, $end),
            'str_excerpt' => static fn (mixed $value, int $limit = 30, string $end = '…'): string =>
                Str::excerpt(self::stringValue($value, 'str_excerpt'), $limit, $end),
            'str_ascii' => static fn (mixed $value): string =>
                Str::ascii(self::stringValue($value, 'str_ascii')),
            'date_format' => static fn (mixed $value, string $format = 'd/m/Y H:i', mixed $timezone = null): string =>
                Date::format($value, $format, $timezone),
            'date_diff_for_humans' => static fn (mixed $value, mixed $now = null, mixed $timezone = null): string =>
                Date::diffForHumans($value, $now, $timezone),
            'arr_get' => static fn (mixed $value, string $key, mixed $default = null): mixed =>
                Arr::get(self::arrayValue($value, 'arr_get'), $key, $default),
            'arr_only' => static fn (mixed $value, array $keys): array =>
                Arr::only(self::arrayValue($value, 'arr_only'), $keys),
            'arr_except' => static fn (mixed $value, array $keys): array =>
                Arr::except(self::arrayValue($value, 'arr_except'), $keys),
            'arr_flatten' => static fn (mixed $value): array =>
                Arr::flatten(self::arrayValue($value, 'arr_flatten')),
        ];
    }

    public function getFilters(): array
    {
        return [
            'slug' => static fn (mixed $value, string $separator = '-'): string =>
                Str::slug(self::stringValue($value, 'slug'), $separator),
            'truncate' => static fn (mixed $value, int $limit = 100, string $end = '…'): string =>
                Str::truncate(self::stringValue($value, 'truncate'), $limit, $end),
            'excerpt' => static fn (mixed $value, int $limit = 30, string $end = '…'): string =>
                Str::excerpt(self::stringValue($value, 'excerpt'), $limit, $end),
            'ascii' => static fn (mixed $value): string =>
                Str::ascii(self::stringValue($value, 'ascii')),
            'date' => static fn (mixed $value, string $format = 'd/m/Y H:i', mixed $timezone = null): string =>
                Date::format($value, $format, $timezone),
            'diff_for_humans' => static fn (mixed $value, mixed $now = null, mixed $timezone = null): string =>
                Date::diffForHumans($value, $now, $timezone),
            'arr_get' => static fn (mixed $value, string $key, mixed $default = null): mixed =>
                Arr::get(self::arrayValue($value, 'arr_get'), $key, $default),
            'arr_only' => static fn (mixed $value, array $keys): array =>
                Arr::only(self::arrayValue($value, 'arr_only'), $keys),
            'arr_except' => static fn (mixed $value, array $keys): array =>
                Arr::except(self::arrayValue($value, 'arr_except'), $keys),
            'flatten' => static fn (mixed $value): array =>
                Arr::flatten(self::arrayValue($value, 'flatten')),
        ];
    }

    private static function stringValue(mixed $value, string $name): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value) && !$value instanceof \Stringable) {
            throw new InvalidArgumentException(sprintf('The "%s" utility expects a string-compatible value.', $name));
        }

        return (string) $value;
    }

    /** @return array<mixed> */
    private static function arrayValue(mixed $value, string $name): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf('The "%s" utility expects an array.', $name));
        }

        return $value;
    }
}
