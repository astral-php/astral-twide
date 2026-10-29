<?php

declare(strict_types=1);

namespace Astral\Twide\Configuration;

use Astral\Twide\Exceptions\InvalidConfigurationException;

final class TwideConfig
{
    public readonly string $viewsPath;
    public readonly string $cachePath;
    public readonly string $extension;
    public readonly bool $debug;
    public readonly bool $cacheEnabled;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options)
    {
        $viewsPath = $options['views'] ?? null;
        if (!is_string($viewsPath) || trim($viewsPath) === '') {
            throw new InvalidConfigurationException(
                'The "views" option must be a non-empty directory path.'
            );
        }

        $cachePath = $options['cache'] ?? sys_get_temp_dir() . '/astral-twide';
        if (!is_string($cachePath) || trim($cachePath) === '') {
            throw new InvalidConfigurationException(
                'The "cache" option must be a non-empty directory path.'
            );
        }

        $extension = $options['extension'] ?? '.twide';
        if (
            !is_string($extension)
            || $extension === ''
            || str_contains($extension, '/')
            || str_contains($extension, '\\')
        ) {
            throw new InvalidConfigurationException(
                'The "extension" option must be a non-empty filename suffix.'
            );
        }

        $this->viewsPath = $viewsPath;
        $this->cachePath = $cachePath;
        $this->extension = $extension;
        $this->debug = (bool) ($options['debug'] ?? false);
        $this->cacheEnabled = (bool) ($options['cache_enabled'] ?? true);
    }
}
