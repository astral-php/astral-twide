<?php

declare(strict_types=1);

namespace Astral\Twide\Loader;

use Astral\Twide\Configuration\TwideConfig;
use Astral\Twide\Exceptions\TemplateNotFoundException;
use InvalidArgumentException;
use RuntimeException;

final class TemplateLoader
{
    private string $rootPath;

    public function __construct(private readonly TwideConfig $config)
    {
        $root = realpath($config->viewsPath);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException(sprintf(
                'The template root directory "%s" does not exist.',
                $config->viewsPath
            ));
        }

        $this->rootPath = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    public function exists(string $name): bool
    {
        try {
            return is_file($this->resolve($name));
        } catch (InvalidArgumentException) {
            return false;
        } catch (TemplateNotFoundException) {
            return false;
        }
    }

    public function load(string $name): string
    {
        $path = $this->resolve($name);
        $source = file_get_contents($path);

        if ($source === false) {
            throw new RuntimeException(sprintf('Unable to read template "%s".', $name));
        }

        return $source;
    }

    public function resolve(string $name): string
    {
        if ($name === '' || str_contains($name, "\0")) {
            throw new InvalidArgumentException('Template name must be a non-empty string.');
        }

        // Template names are logical identifiers, not arbitrary filesystem paths.
        if (str_starts_with($name, '/') || str_starts_with($name, '\\') || preg_match('/^[A-Za-z]:/', $name)) {
            throw new InvalidArgumentException('Absolute template paths are not allowed.');
        }

        $normalized = str_replace('\\', '/', $name);
        $segments = explode('/', $normalized);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Invalid template path segment.');
            }
        }

        $relative = $normalized;
        if (!str_ends_with($relative, $this->config->extension)) {
            $relative .= $this->config->extension;
        }

        $candidate = realpath($this->rootPath . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if ($candidate === false || !is_file($candidate)) {
            throw new TemplateNotFoundException(sprintf('Template "%s" was not found.', $name));
        }

        // realpath also blocks symlink escapes from the configured root.
        if (!str_starts_with($candidate, $this->rootPath)) {
            throw new InvalidArgumentException('Template path escapes the configured views directory.');
        }

        return $candidate;
    }
}
