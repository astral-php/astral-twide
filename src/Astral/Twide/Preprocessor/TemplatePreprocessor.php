<?php

declare(strict_types=1);

namespace Astral\Twide\Preprocessor;

use Astral\Twide\Exceptions\TwideException;
use Astral\Twide\Loader\TemplateLoader;

/**
 * Resolves static template composition directives before compilation.
 *
 * Supported directives:
 *   @extends('layouts/base')
 *   @section('content') ... @endsection
 *   @yield('content')
 *   @yield('content', 'fallback')
 *   @include('partials/card')
 *
 * Template names and section names are intentionally static string literals.
 */
final class TemplatePreprocessor
{
    private const MAX_DEPTH = 32;

    public function __construct(private readonly TemplateLoader $loader)
    {
    }

    public function process(string $template, string $source): string
    {
        // Comments must not trigger composition directives during preprocessing.
        $source = preg_replace('/\\{\\{--.*?--\\}\\}/s', '', $source) ?? $source;
        $expanded = $this->expand($template, $source, [], [], 0);
        return $this->resolveStacks($expanded);
    }

    /**
     * @param array<string, string> $inheritedSections
     * @param list<string> $stack
     */
    private function expand(
        string $template,
        string $source,
        array $inheritedSections,
        array $stack,
        int $depth
    ): string {
        if ($depth > self::MAX_DEPTH) {
            throw new TwideException('Template composition exceeded the maximum nesting depth of ' . self::MAX_DEPTH . '.');
        }
        if (in_array($template, $stack, true)) {
            $stack[] = $template;
            throw new TwideException('Circular template composition detected: ' . implode(' -> ', $stack) . '.');
        }
        $stack[] = $template;

        $extends = $this->extractSingleNameDirective($source, 'extends');
        $sections = $inheritedSections;

        // Child sections are already present in $inheritedSections and take precedence.
        if (preg_match_all('/@section\s*\(\s*([\'"])([A-Za-z_][A-Za-z0-9_.-]*)\1\s*\)(.*?)@endsection/s', $source, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (!array_key_exists($match[2], $sections)) {
                    $sections[$match[2]] = $match[3];
                }
            }
        }

        if ($extends !== null) {
            return $this->expand($extends, $this->loader->load($extends), $sections, $stack, $depth + 1);
        }

        // Sections are definitions; remove their wrappers and bodies from ordinary output.
        $source = preg_replace('/@section\s*\(\s*([\'"])([A-Za-z_][A-Za-z0-9_.-]*)\1\s*\).*?@endsection/s', '', $source) ?? $source;

        // Replace yields first so includes declared inside a section are expanded as well.
        $source = preg_replace_callback(
            '/@yield\s*\(\s*([\'"])([A-Za-z_][A-Za-z0-9_.-]*)\1\s*(?:,\s*([\'"])(.*?)\3\s*)?\)/s',
            static function (array $match) use ($sections): string {
                $name = $match[2];
                return array_key_exists($name, $sections) ? $sections[$name] : ($match[4] ?? '');
            },
            $source
        ) ?? $source;

        // Expand includes recursively. The same render context is shared with included templates.
        $source = preg_replace_callback(
            '/@include\s*\(\s*([\'"])([^\'"]+)\1\s*\)/',
            function (array $match) use ($stack, $depth, $sections): string {
                $name = trim($match[2]);
                if ($name === '') {
                    throw new TwideException('The @include directive requires a template name.');
                }
                if (in_array($name, $stack, true)) {
                    $chain = $stack;
                    $chain[] = $name;
                    throw new TwideException('Circular template inclusion detected: ' . implode(' -> ', $chain) . '.');
                }
                return $this->expand($name, $this->loader->load($name), $sections, $stack, $depth + 1);
            },
            $source
        ) ?? $source;

        $source = $this->expandComponents($source, $stack, $depth, $sections);

        if (preg_match('/@(?:extends|section|endsection|yield|include|component|endcomponent|slot|endslot)\b/', $source, $unknown)) {
            throw new TwideException('Unresolved template composition directive "' . $unknown[0] . '".');
        }

        return $source;
    }

    /**
     * Collect pushed content from the fully expanded template, then render each
     * stack placeholder. This final pass lets pushes inside sections, includes
     * and components contribute to a stack declared in the layout.
     *
     * @return string
     */
    private function resolveStacks(string $source): string
    {
        /** @var array<string, list<string>> $stacks */
        $stacks = [];
        // Directives end before uppercase/content (e.g. @endpushWidget → @endpush + Widget).
        // Lowercase continuation keeps a longer unknown token (@endpushes) unmatched.
        $pushOpenCount = preg_match_all('/@push\s*\(/', $source);
        $pushCloseCount = preg_match_all('/@endpush(?![a-z])/', $source);
        if ($pushOpenCount !== $pushCloseCount) {
            throw new TwideException('Every @push directive must have a matching @endpush.');
        }
        $source = preg_replace_callback(
            '/@push\s*\(\s*([\'"])([A-Za-z_][A-Za-z0-9_.-]*)\1\s*\)(.*?)@endpush(?![a-z])/s',
            static function (array $match) use (&$stacks): string {
                $stacks[$match[2]][] = $match[3];
                return '';
            },
            $source
        ) ?? $source;

        $source = preg_replace_callback(
            '/@stack\s*\(\s*([\'"])([A-Za-z_][A-Za-z0-9_.-]*)\1\s*\)/',
            static function (array $match) use ($stacks): string {
                return implode('', $stacks[$match[2]] ?? []);
            },
            $source
        ) ?? $source;

        if (preg_match('/@(?:push|endpush|stack)(?![a-z])/', $source, $unknown)) {
            throw new TwideException('Unresolved content stack directive "' . $unknown[0] . '".');
        }

        return $source;
    }

    /**
     * Expand @component('name') ... @endcomponent blocks.
     *
     * Named slot blocks use @slot('name') ... @endslot. The remaining body
     * becomes the default "slot". Component and slot names must be literals.
     *
     * @param list<string> $stack
     * @param array<string, string> $sections
     */
    private function expandComponents(string $source, array $stack, int $depth, array $sections): string
    {
        $pattern = '/@component\s*\(\s*([\'"])([^\'"]+)\1\s*\)/';
        $cursor = 0;
        $output = '';

        while (preg_match($pattern, $source, $start, PREG_OFFSET_CAPTURE, $cursor)) {
            $directive = $start[0][0];
            $startOffset = $start[0][1];
            $bodyOffset = $startOffset + strlen($directive);
            $output .= substr($source, $cursor, $startOffset);

            $tokensPattern = '/@component\s*\(\s*([\'"])([^\'"]+)\1\s*\)|@endcomponent\b/';
            $scan = $bodyOffset;
            $nesting = 1;
            $endOffset = null;
            $endLength = 0;
            while (preg_match($tokensPattern, $source, $token, PREG_OFFSET_CAPTURE, $scan)) {
                $tokenText = $token[0][0];
                $tokenOffset = $token[0][1];
                if (str_starts_with($tokenText, '@component')) {
                    $nesting++;
                } else {
                    $nesting--;
                    if ($nesting === 0) {
                        $endOffset = $tokenOffset;
                        $endLength = strlen($tokenText);
                        break;
                    }
                }
                $scan = $tokenOffset + strlen($tokenText);
            }

            if ($endOffset === null) {
                throw new TwideException('Missing @endcomponent for @component("' . trim($start[2][0]) . '").');
            }

            $name = trim($start[2][0]);
            if ($name === '') {
                throw new TwideException('@component requires a template name.');
            }
            $body = substr($source, $bodyOffset, $endOffset - $bodyOffset);
            $componentSections = $sections;
            $slotOpenCount = preg_match_all('/@slot\s*\(/', $body);
            $slotCloseCount = preg_match_all('/@endslot(?![a-z])/', $body);
            if ($slotOpenCount !== $slotCloseCount) {
                throw new TwideException('Every @slot directive must have a matching @endslot.');
            }
            $defaultSlot = preg_replace_callback(
                '/@slot\s*\(\s*([\'"])([A-Za-z_][A-Za-z0-9_.-]*)\1\s*\)(.*?)@endslot(?![a-z])/s',
                static function (array $match) use (&$componentSections): string {
                    $componentSections[$match[2]] = $match[3];
                    return '';
                },
                $body
            ) ?? $body;
            $componentSections['slot'] = $defaultSlot;

            $output .= $this->expand(
                $name,
                $this->loader->load($name),
                $componentSections,
                $stack,
                $depth + 1
            );
            $cursor = $endOffset + $endLength;
        }

        $output .= substr($source, $cursor);

        if (preg_match('/@(?:endcomponent|slot|endslot)\b/', $output, $unknown)) {
            throw new TwideException('Unmatched component directive "' . $unknown[0] . '".');
        }

        return $output;
    }

    private function extractSingleNameDirective(string $source, string $directive): ?string
    {
        $pattern = '/@' . preg_quote($directive, '/') . '\s*\(\s*([\'"])([^\'"]+)\1\s*\)/';
        if (!preg_match_all($pattern, $source, $matches)) {
            return null;
        }
        if (count($matches[0]) !== 1) {
            throw new TwideException('A template must declare at most one @' . $directive . ' directive.');
        }
        $first = strpos($source, $matches[0][0]);
        $before = $first === false ? '' : substr($source, 0, $first);
        if (trim($before) !== '') {
            throw new TwideException('@' . $directive . ' must appear before template output.');
        }
        return trim($matches[2][0]);
    }
}
