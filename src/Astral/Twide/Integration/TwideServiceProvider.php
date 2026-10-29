<?php

declare(strict_types=1);

namespace Astral\Twide\Integration;

use Astral\Twide\Contracts\TemplateEngineInterface;
use Astral\Twide\Extension\UtilitiesExtension;
use Astral\Twide\Twide;
use Core\Container;
use Core\ServiceProviderInterface;
use RuntimeException;

/**
 * Registers Twide as an optional Astral MVC component.
 *
 * Defaults follow the Astral application layout:
 * - templates: BASE_PATH/views
 * - compiled templates: BASE_PATH/storage/cache/views
 *
 * Optional overrides may be supplied under $appConfig['twide']['views'] and
 * $appConfig['twide']['cache']; ordinary Astral applications need no extra
 * configuration for the standard paths.
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
            throw new RuntimeException('The optional appConfig["twide"] value must be an array.');
        }

        $viewsPath = $twideConfig['views'] ?? $basePath . DIRECTORY_SEPARATOR . 'views';
        $cachePath = $twideConfig['cache'] ?? $basePath . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'views';

        if (!is_string($viewsPath) || trim($viewsPath) === '') {
            throw new RuntimeException('The Twide views path must be a non-empty string.');
        }
        if (!is_string($cachePath) || trim($cachePath) === '') {
            throw new RuntimeException('The Twide cache path must be a non-empty string.');
        }

        $debug = (bool) ($appConfig['debug'] ?? false);

        $container->singleton(Twide::class, static function () use ($viewsPath, $cachePath, $debug): Twide {
            $engine = new Twide([
                'views' => $viewsPath,
                'cache' => $cachePath,
                'extension' => '.twide',
                'debug' => $debug,
                'cache_enabled' => true,
            ]);

            // Utilities are available by default when Twide is installed as an Astral component.
            $engine->addExtension(new UtilitiesExtension());

            return $engine;
        });

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
}
