<?php

declare(strict_types=1);

namespace Astral\Twide\Integration;

use Astral\Twide\Contracts\ExtensionInterface;
use Astral\Twide\Contracts\TemplateEngineInterface;
use Astral\Twide\Extension\UtilitiesExtension;
use Astral\Twide\Twide;
use Core\Container;
use Core\ServiceProviderInterface;
use RuntimeException;
use Throwable;

/**
 * Registers Twide as an optional Astral MVC component.
 *
 * Defaults follow the Astral application layout:
 * - templates: BASE_PATH/views (extension .twide)
 * - compiled templates: BASE_PATH/storage/cache/views
 *
 * Optional overrides under $appConfig['twide']:
 * - views, cache, extension
 * - extensions: list of ExtensionInterface FQCNs (application packages)
 */
final class TwideServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container, array $appConfig, array $dbConfig): void
    {
        if (!defined('BASE_PATH') || !is_string(BASE_PATH) || trim(BASE_PATH) === '') {
            throw new RuntimeException(
                'TwideServiceProvider requires Astral BASE_PATH to be defined before providers are registered.'
            );
        }

        $basePath = rtrim(BASE_PATH, DIRECTORY_SEPARATOR);
        $twideConfig = $appConfig['twide'] ?? [];
        if (!is_array($twideConfig)) {
            throw new RuntimeException(
                'Twide: appConfig["twide"] must be an array when provided.'
            );
        }

        $viewsPath = $twideConfig['views'] ?? $basePath . DIRECTORY_SEPARATOR . 'views';
        $cachePath = $twideConfig['cache'] ?? $basePath . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'views';
        $extension = $twideConfig['extension'] ?? '.twide';
        $extensions = $twideConfig['extensions'] ?? [];

        if (!is_string($viewsPath) || trim($viewsPath) === '') {
            throw new RuntimeException('Twide: "twide.views" must be a non-empty string.');
        }
        if (!is_string($cachePath) || trim($cachePath) === '') {
            throw new RuntimeException('Twide: "twide.cache" must be a non-empty string.');
        }
        if (!is_string($extension) || $extension === '' || str_contains($extension, '/') || str_contains($extension, '\\')) {
            throw new RuntimeException(
                'Twide: "twide.extension" must be a non-empty filename suffix (e.g. ".twide"), without directory separators.'
            );
        }
        if (!is_array($extensions)) {
            throw new RuntimeException(
                'Twide: "twide.extensions" must be a list of class-string values implementing ExtensionInterface.'
            );
        }

        $debug = (bool) ($appConfig['debug'] ?? false);

        $container->singleton(
            Twide::class,
            static function (Container $c) use ($viewsPath, $cachePath, $extension, $extensions, $debug): Twide {
                $engine = new Twide([
                    'views' => $viewsPath,
                    'cache' => $cachePath,
                    'extension' => $extension,
                    'debug' => $debug,
                    'cache_enabled' => true,
                ]);

                $engine->addExtension(new UtilitiesExtension());

                foreach ($extensions as $index => $className) {
                    $engine->addExtension(self::resolveExtension($c, $className, $index));
                }

                return $engine;
            },
        );

        $container->singleton(
            TemplateEngineInterface::class,
            static fn (Container $c): TemplateEngineInterface => $c->make(Twide::class),
        );

        $container->singleton(
            TwideViewAdapter::class,
            static fn (Container $c): TwideViewAdapter => new TwideViewAdapter(
                $c->make(TemplateEngineInterface::class),
            ),
        );
    }

    private static function resolveExtension(Container $container, mixed $className, int|string $index): ExtensionInterface
    {
        $label = is_int($index) ? (string) $index : $index;

        if (!is_string($className) || trim($className) === '') {
            throw new RuntimeException(
                'Twide: twide.extensions[' . $label . '] must be a non-empty class-string implementing ExtensionInterface.'
            );
        }

        if (!class_exists($className)) {
            throw new RuntimeException(
                'Twide: extension class "' . $className . '" (twide.extensions[' . $label . ']) was not found. '
                . 'Check the FQCN and Composer autoload.'
            );
        }

        try {
            $instance = $container->make($className);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Twide: failed to resolve extension "' . $className . '" (twide.extensions[' . $label . ']): '
                . $e->getMessage(),
                0,
                $e
            );
        }

        if (!$instance instanceof ExtensionInterface) {
            throw new RuntimeException(
                'Twide: "' . $className . '" must implement Astral\\Twide\\Contracts\\ExtensionInterface '
                . '(twide.extensions[' . $label . ']).'
            );
        }

        return $instance;
    }
}
