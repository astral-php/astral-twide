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
 *   @include('partials/card')
 *   @include('partials/card' with dataExpr)
 *   @each('partial', items, 'item'[, empty])
 *   @component / @slot / @push / @stack
 *
 * Template names for composition are static string literals (security).
 * `@include … with` is rewritten to `@scope(expr)…@endscope` around the
 * expanded partial so the compiler merges context at runtime.
 */
final class TemplatePreprocessor
{
    private const MAX_DEPTH = 32;

    private DirectiveArgumentSplitter $arguments;

    public function __construct(private readonly TemplateLoader $loader)
    {
        $this->arguments = new DirectiveArgumentSplitter();
    }

    public function process(string $template, string $source): string
    {
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

        $source = preg_replace('/@section\s*\(\s*([\'"])([A-Za-z_][A-Za-z0-9_.-]*)\1\s*\).*?@endsection/s', '', $source) ?? $source;

        $source = preg_replace_callback(
            '/@yield\s*\(\s*([\'"])([A-Za-z_][A-Za-z0-9_.-]*)\1\s*(?:,\s*([\'"])(.*?)\3\s*)?\)/s',
            static function (array $match) use ($sections): string {
                $name = $match[2];
                return array_key_exists($name, $sections) ? $sections[$name] : ($match[4] ?? '');
            },
            $source
        ) ?? $source;

        // @each → @forelse + @include (+ empty) before include expansion.
        $source = $this->rewriteEachDirectives($source);

        $source = $this->expandIncludes($source, $stack, $depth, $sections);
        $source = $this->expandComponents($source, $stack, $depth, $sections);

        if (preg_match('/@(?:extends|section|endsection|yield|include|each|component|endcomponent|slot|endslot)(?![a-z])/', $source, $unknown)) {
            throw new TwideException('Unresolved template composition directive "' . $unknown[0] . '".');
        }

        return $source;
    }

    /**
     * @param list<string> $stack
     * @param array<string, string> $sections
     */
    private function expandIncludes(string $source, array $stack, int $depth, array $sections): string
    {
        $offset = 0;
        $length = strlen($source);
        $output = '';

        while ($offset < $length) {
            if (!preg_match('/@include\s*\(/', $source, $match, PREG_OFFSET_CAPTURE, $offset)) {
                $output .= substr($source, $offset);
                break;
            }

            $start = $match[0][1];
            $openParen = $start + strlen($match[0][0]) - 1;
            $close = $this->findBalancedParenthesis($source, $openParen);
            if ($close === null) {
                throw new TwideException('Unclosed argument list for @include.');
            }

            $output .= substr($source, $offset, $start - $offset);
            $inner = trim(substr($source, $openParen + 1, $close - $openParen - 1));
            $output .= $this->expandOneInclude($inner, $stack, $depth, $sections);
            $offset = $close + 1;
        }

        return $output;
    }

    /**
     * @param list<string> $stack
     * @param array<string, string> $sections
     */
    private function expandOneInclude(string $inner, array $stack, int $depth, array $sections): string
    {
        $withExpr = null;
        $namePart = $inner;
        if (preg_match('/^(.+?)\s+with\s+(.+)$/s', $inner, $parts)) {
            $namePart = trim($parts[1]);
            $withExpr = trim($parts[2]);
            if ($withExpr === '') {
                throw new TwideException(
                    'Invalid @include with syntax. Expected @include(\'partial\' with expression).'
                );
            }
        }

        if (!preg_match('/^([\'"])([^\'"]+)\1$/', $namePart, $nameMatch)) {
            throw new TwideException(
                'Twide: @include template name must be a static quoted string (got "' . $namePart . '").'
            );
        }

        $name = trim($nameMatch[2]);
        if ($name === '') {
            throw new TwideException('The @include directive requires a template name.');
        }
        if (in_array($name, $stack, true)) {
            $chain = $stack;
            $chain[] = $name;
            throw new TwideException('Circular template inclusion detected: ' . implode(' -> ', $chain) . '.');
        }

        $expanded = $this->expand($name, $this->loader->load($name), $sections, $stack, $depth + 1);
        if ($withExpr === null) {
            return $expanded;
        }

        return '@scope(' . $withExpr . ')' . $expanded . '@endscope';
    }

    private function rewriteEachDirectives(string $source): string
    {
        $offset = 0;
        $length = strlen($source);
        $output = '';

        while ($offset < $length) {
            if (!preg_match('/@each\s*\(/', $source, $match, PREG_OFFSET_CAPTURE, $offset)) {
                $output .= substr($source, $offset);
                break;
            }

            $start = $match[0][1];
            $openParen = $start + strlen($match[0][0]) - 1;
            $close = $this->findBalancedParenthesis($source, $openParen);
            if ($close === null) {
                throw new TwideException('Unclosed argument list for @each.');
            }

            $output .= substr($source, $offset, $start - $offset);
            $inner = trim(substr($source, $openParen + 1, $close - $openParen - 1));
            $output .= $this->compileEachRewrite($inner);
            $offset = $close + 1;
        }

        return $output;
    }

    private function compileEachRewrite(string $inner): string
    {
        $args = $this->arguments->split($inner);
        if (count($args) < 3 || count($args) > 4) {
            throw new TwideException(
                'Twide: @each expects @each(\'template\', items, \'itemName\'[, empty]).'
            );
        }

        if (!preg_match('/^([\'"])([^\'"]+)\1$/', $args[0], $tpl)) {
            throw new TwideException('Twide: @each template name must be a static quoted string.');
        }
        $template = $tpl[2];
        $items = $args[1];

        if (!preg_match('/^([\'"])([A-Za-z_][A-Za-z0-9_]*)\1$/', $args[2], $item)) {
            throw new TwideException(
                'Twide: @each item name must be a quoted identifier (e.g. \'user\').'
            );
        }
        $itemName = $item[2];
        if ($itemName === 'loop') {
            throw new TwideException('Twide: @each item name cannot be the reserved name "loop".');
        }

        $emptyBranch = '';
        if (isset($args[3])) {
            $emptyBranch = $this->compileEachEmpty($args[3]);
        }

        return '@forelse(' . $items . ' as ' . $itemName . ')'
            . '@include(\'' . str_replace(['\\', '\''], ['\\\\', '\\\''], $template) . '\')'
            . '@empty'
            . $emptyBranch
            . '@endforelse';
    }

    private function compileEachEmpty(string $emptyArg): string
    {
        $emptyArg = trim($emptyArg);
        if (preg_match('/^([\'"])raw\|(.*)\1$/s', $emptyArg, $raw)) {
            return htmlspecialchars($raw[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        if (preg_match('/^([\'"])([^\'"]+)\1$/', $emptyArg, $tpl)) {
            return '@include(\'' . str_replace(['\\', '\''], ['\\\\', '\\\''], $tpl[2]) . '\')';
        }

        throw new TwideException(
            'Twide: @each empty argument must be a quoted template name or \'raw|literal text\'.'
        );
    }

    /**
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

    private function resolveStacks(string $source): string
    {
        /** @var array<string, list<string>> $stacks */
        $stacks = [];
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

    private function findBalancedParenthesis(string $source, int $open): ?int
    {
        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($source);
        for ($i = $open; $i < $length; $i++) {
            $char = $source[$i];
            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }
}
