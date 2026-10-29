<?php

declare(strict_types=1);

namespace Astral\Twide;

use Astral\Twide\Configuration\TwideConfig;
use Astral\Twide\Contracts\ExtensionInterface;
use Astral\Twide\Contracts\TemplateEngineInterface;
use Astral\Twide\Exceptions\TwideException;
use Astral\Twide\Loader\TemplateLoader;
use Astral\Twide\Registry\FilterRegistry;
use Astral\Twide\Registry\FunctionRegistry;
use Astral\Twide\Runtime\TwideRuntime;
use Astral\Twide\Preprocessor\TemplatePreprocessor;
use Astral\Twide\Compiler\TemplateCompiler;
use RuntimeException;

/**
 * End-to-end Twide engine with template composition, components, slots and stacks.
 * Astral MVC integration is exposed through a framework-neutral adapter.
 */
final class Twide implements TemplateEngineInterface
{
    private TwideConfig $config;
    private TemplateLoader $loader;
    private TemplateCompiler $compiler;
    private TemplatePreprocessor $preprocessor;
    private FunctionRegistry $functions;
    private FilterRegistry $filters;

    /** @param array<string, mixed> $options */
    public function __construct(array $options)
    {
        $this->config = new TwideConfig($options);
        $this->loader = new TemplateLoader($this->config);
        $this->compiler = new TemplateCompiler();
        $this->preprocessor = new TemplatePreprocessor($this->loader);
        $this->functions = new FunctionRegistry();
        $this->filters = new FilterRegistry();

        $this->registerBuiltinFunctions();
        $this->registerBuiltinFilters();
    }

    public function addFunction(string $name, callable $function, bool $replace = false): self
    {
        $this->functions->register($name, $function, $replace);
        return $this;
    }

    public function addFilter(string $name, callable $filter, bool $replace = false): self
    {
        $this->filters->register($name, $filter, $replace);
        return $this;
    }

    public function addExtension(ExtensionInterface $extension, bool $replace = false): self
    {
        foreach ($extension->getFunctions() as $name => $function) {
            $this->addFunction($name, $function, $replace);
        }
        foreach ($extension->getFilters() as $name => $filter) {
            $this->addFilter($name, $filter, $replace);
        }
        return $this;
    }

    public function render(string $template, array $data = []): string
    {
        $source = $this->preprocessor->process($template, $this->loader->load($template));
        $cacheFile = $this->compiledPath($template, $source);

        if (!$this->config->cacheEnabled || !is_file($cacheFile)) {
            $compiled = $this->compiler->compile($source, $template);
            if ($this->config->cacheEnabled) {
                $this->writeCache($cacheFile, $compiled);
            } else {
                $cacheFile = $this->writeTemporaryCompiled($compiled);
            }
        }

        try {
            /** @var callable(TwideRuntime, array<string,mixed>):string $renderer */
            $renderer = require $cacheFile;
            $runtime = new TwideRuntime($this->functions, $this->filters);
            return $renderer($runtime, $data);
        } catch (\Throwable $exception) {
            if ($exception instanceof TwideException) {
                throw $exception;
            }
            throw new TwideException(
                sprintf('Failed to render template "%s": %s', $template, $exception->getMessage()),
                (int) $exception->getCode(),
                $exception
            );
        } finally {
            if (!$this->config->cacheEnabled && isset($cacheFile) && is_file($cacheFile)) {
                @unlink($cacheFile);
            }
        }
    }

    public function exists(string $template): bool
    {
        return $this->loader->exists($template);
    }

    private function compiledPath(string $template, string $source): string
    {
        $signature = hash('sha256', $template . "\0" . $source . "\0" . PHP_VERSION_ID . "\0twide-0.1-phase-13");
        return rtrim($this->config->cachePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $signature . '.php';
    }

    private function writeCache(string $path, string $compiled): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create Twide cache directory "%s".', $directory));
        }
        $temporary = tempnam($directory, 'twide-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to create temporary Twide cache file.');
        }
        try {
            if (file_put_contents($temporary, $compiled, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write compiled Twide template.');
            }
            // Compiled templates are executable PHP: keep them owner-readable/writable only.
            @chmod($temporary, 0600);
            if (!rename($temporary, $path)) {
                throw new RuntimeException('Unable to publish compiled Twide template.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function writeTemporaryCompiled(string $compiled): string
    {
        $directory = rtrim($this->config->cachePath, DIRECTORY_SEPARATOR);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create Twide cache directory "%s".', $directory));
        }
        $path = tempnam($directory, 'twide-dev-');
        if ($path === false || file_put_contents($path, $compiled, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write temporary compiled template.');
        }
        @chmod($path, 0600);
        return $path;
    }

    private function registerBuiltinFunctions(): void
    {
        $this->functions->register('count', static fn (mixed $value): int => is_countable($value) ? count($value) : 0);
        $this->functions->register('upper', static fn (string $value): string => strtoupper($value));
        $this->functions->register('lower', static fn (string $value): string => strtolower($value));
    }

    private function registerBuiltinFilters(): void
    {
        $this->filters->register('upper', static fn (mixed $value): string => strtoupper((string) $value));
        $this->filters->register('lower', static fn (mixed $value): string => strtolower((string) $value));
        $this->filters->register('trim', static fn (mixed $value): string => trim((string) $value));
        $this->filters->register('default', static fn (mixed $value, mixed $fallback = ''): mixed => $value ?? $fallback);
        $this->filters->register('length', static fn (mixed $value): int => is_countable($value) ? count($value) : (is_string($value) ? strlen($value) : 0));
    }
}
