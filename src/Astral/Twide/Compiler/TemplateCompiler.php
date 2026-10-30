<?php

declare(strict_types=1);

namespace Astral\Twide\Compiler;

use Astral\Twide\Exceptions\TwideException;
use Astral\Twide\Parser\TemplateParser;

/**
 * Compiles the Twide AST into a PHP closure body.
 *
 * The generated code is stored in a private cache directory.
 * It never evaluates user-supplied PHP source.
 */
final class TemplateCompiler
{
    private ExpressionCompiler $expressionCompiler;
    private TemplateParser $parser;
    private int $temporaryCounter = 0;

    public function __construct(
        ?TemplateParser $parser = null,
        ?ExpressionCompiler $expressionCompiler = null,
    ) {
        $this->parser = $parser ?? new TemplateParser();
        $this->expressionCompiler = $expressionCompiler ?? new ExpressionCompiler();
    }

    public function compile(string $source, string $templateName = 'inline'): string
    {
        $this->temporaryCounter = 0;
        $nodes = $this->parser->parse($source, $templateName);
        $body = $this->compileNodes($nodes);

        return "<?php\n"
            . "declare(strict_types=1);\n"
            . "/* Compiled by Astral Twide. Template: " . str_replace('*/', '* /', $templateName) . " */\n"
            . "return static function (\\Astral\\Twide\\Runtime\\TwideRuntime \$__twide_runtime, array \$__twide_context): string {\n"
            . "    \$__twide_output = '';\n"
            . $this->indent($body, 1)
            . "    return \$__twide_output;\n"
            . "};\n";
    }

    /** @param list<array<string, mixed>> $nodes */
    private function compileNodes(array $nodes): string
    {
        $code = '';
        foreach ($nodes as $node) {
            $code .= match ($node['type']) {
                'text' => '$__twide_output .= ' . var_export($node['value'], true) . ";\n",
                'echo' => '$__twide_output .= $__twide_runtime->escape('
                    . $this->expressionCompiler->compile($node['expression']) . ");\n",
                'raw_echo' => '$__twide_output .= $__twide_runtime->raw('
                    . $this->expressionCompiler->compile($node['expression']) . ");\n",
                'if' => $this->compileIf($node),
                'for' => $this->compileIterableLoop($node, null, null),
                'foreach' => $this->compileIterableLoop($node, $node['key'], $node['condition']),
                'forelse' => $this->compileForelse($node),
                'for_range' => $this->compileForRange($node),
                'while' => $this->compileWhile($node),
                'isset' => 'if (' . $this->expressionCompiler->compileIsset($node['expression']) . ") {\n"
                    . $this->indent($this->compileNodes($node['body']), 1) . "}\n",
                'empty' => 'if ($__twide_runtime->isEmpty('
                    . $this->expressionCompiler->compile($node['expression']) . ")) {\n"
                    . $this->indent($this->compileNodes($node['body']), 1) . "}\n",
                'switch' => $this->compileSwitch($node),
                'break' => "break;\n",
                'continue' => "continue;\n",
                'set' => '$__twide_context[' . var_export($node['variable'], true) . '] = '
                    . $this->expressionCompiler->compile($node['expression']) . ";\n",
                'scope' => $this->compileScope($node),
                default => throw new TwideException('Unsupported template node "' . $node['type'] . '".'),
            };
        }

        return $code;
    }

    /** @param array<string, mixed> $node */
    private function compileIf(array $node): string
    {
        $code = '';
        foreach ($node['branches'] as $index => $branch) {
            $code .= ($index === 0 ? 'if' : 'elseif') . ' ('
                . $this->expressionCompiler->compile($branch['condition']) . ") {\n";
            $code .= $this->indent($this->compileNodes($branch['body']), 1);
            $code .= "}\n";
        }
        if ($node['else'] !== []) {
            $code .= "else {\n" . $this->indent($this->compileNodes($node['else']), 1) . "}\n";
        }

        return $code;
    }

    /** @param array<string, mixed> $node */
    private function compileSwitch(array $node): string
    {
        $code = 'switch (' . $this->expressionCompiler->compile($node['expression']) . ") {\n";
        foreach ($node['cases'] as $case) {
            $code .= 'case ' . $this->expressionCompiler->compile($case['expression']) . ":\n";
            $code .= $this->indent($this->compileNodes($case['body']), 1);
        }
        if ($node['default'] !== null) {
            $code .= "default:\n";
            $code .= $this->indent($this->compileNodes($node['default']), 1);
        }
        $code .= "}\n";

        return $code;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $condition
     */
    private function compileIterableLoop(array $node, ?string $keyName, ?array $condition): string
    {
        $id = ++$this->temporaryCounter;
        $tmp = '$__twide_items_' . $id;
        $key = '$__twide_key_' . $id;
        $var = '$__twide_item_' . $id;
        $index = '$__twide_index_' . $id;
        $loopName = (string) $node['variable'];

        $code = $tmp . ' = $__twide_runtime->iterable('
            . $this->expressionCompiler->compile($node['expression']) . ");\n";
        $code .= $this->saveContextVars($id, $loopName, $keyName);
        $code .= $index . " = 0;\n";
        $code .= 'foreach (' . $tmp . ' as ' . $key . ' => ' . $var . ") {\n";
        $code .= '    $__twide_context[' . var_export($loopName, true) . '] = ' . $var . ";\n";
        if ($keyName !== null) {
            $code .= '    $__twide_context[' . var_export($keyName, true) . '] = ' . $key . ";\n";
        }
        $code .= $this->indent($this->assignLoopMeta($index, $tmp, $key, '$__twide_previous_loop_' . $id), 1);
        $body = $this->compileNodes($node['body']);
        if ($condition !== null) {
            $body = 'if (' . $this->expressionCompiler->compile($condition) . ") {\n"
                . $this->indent($body, 1) . "}\n";
        }
        $code .= $this->indent($body, 1);
        $code .= '    ' . $index . "++;\n";
        $code .= "}\n";
        $code .= $this->restoreContextVars($id, $loopName, $keyName);

        return $code;
    }

    /** @param array<string, mixed> $node */
    private function compileForelse(array $node): string
    {
        $id = ++$this->temporaryCounter;
        $tmp = '$__twide_items_' . $id;
        $key = '$__twide_key_' . $id;
        $var = '$__twide_item_' . $id;
        $index = '$__twide_index_' . $id;
        $loopName = (string) $node['variable'];
        $keyName = $node['key'];

        $code = $tmp . ' = $__twide_runtime->iterable('
            . $this->expressionCompiler->compile($node['expression']) . ");\n";
        $code .= 'if (count(' . $tmp . ") === 0) {\n";
        $code .= $this->indent($this->compileNodes($node['empty']), 1);
        $code .= "} else {\n";
        $code .= $this->indent($this->saveContextVars($id, $loopName, $keyName), 1);
        $code .= $this->indent($index . ' = 0;', 1) . "\n";
        $code .= $this->indent('foreach (' . $tmp . ' as ' . $key . ' => ' . $var . ') {', 1) . "\n";
        $inner = '    $__twide_context[' . var_export($loopName, true) . '] = ' . $var . ";\n";
        if (is_string($keyName)) {
            $inner .= '    $__twide_context[' . var_export($keyName, true) . '] = ' . $key . ";\n";
        }
        $inner .= $this->assignLoopMeta($index, $tmp, $key, '$__twide_previous_loop_' . $id);
        $inner .= $this->compileNodes($node['body']);
        $inner .= '    ' . $index . "++;\n";
        $code .= $this->indent($inner, 1);
        $code .= $this->indent("}", 1) . "\n";
        $code .= $this->indent($this->restoreContextVars($id, $loopName, is_string($keyName) ? $keyName : null), 1);
        $code .= "}\n";

        return $code;
    }

    /** @param array<string, mixed> $node */
    private function compileForRange(array $node): string
    {
        $id = ++$this->temporaryCounter;
        $start = '$__twide_start_' . $id;
        $count = '$__twide_count_' . $id;
        $end = '$__twide_end_' . $id;
        $var = '$__twide_item_' . $id;
        $index = '$__twide_index_' . $id;
        $loopName = (string) $node['variable'];

        $code = $start . ' = (int) (' . $this->expressionCompiler->compile($node['start']) . ");\n";
        $code .= $count . ' = (int) (' . $this->expressionCompiler->compile($node['count']) . ");\n";
        $code .= $end . ' = ' . $start . ' + ' . $count . ";\n";
        $code .= $this->saveContextVars($id, $loopName, null);
        $code .= $index . " = 0;\n";
        $code .= 'for (' . $var . ' = ' . $start . '; ' . $var . ' < ' . $end . '; ' . $var . '++, ' . $index . "++) {\n";
        $code .= '    $__twide_context[' . var_export($loopName, true) . '] = ' . $var . ";\n";
        $code .= "    \$__twide_context['loop'] = ["
            . "'index' => " . $index . " + 1, "
            . "'index0' => " . $index . ", "
            . "'first' => " . $index . " === 0, "
            . "'last' => (" . $index . " + 1) === " . $count . ", "
            . "'length' => " . $count . ", "
            . "'key' => " . $index . ", "
            . "'parent' => \$__twide_previous_loop_" . $id . "];\n";
        $code .= $this->indent($this->compileNodes($node['body']), 1);
        $code .= "}\n";
        $code .= $this->restoreContextVars($id, $loopName, null);

        return $code;
    }

    /** @param array<string, mixed> $node */
    private function compileScope(array $node): string
    {
        $id = ++$this->temporaryCounter;
        $saved = '$__twide_scope_saved_' . $id;
        $code = $saved . ' = $__twide_runtime->pushScope($__twide_context, '
            . $this->expressionCompiler->compile($node['expression']) . ");\n";
        $code .= "try {\n";
        $code .= $this->indent($this->compileNodes($node['body']), 1);
        $code .= "} finally {\n";
        $code .= '    $__twide_runtime->popScope($__twide_context, ' . $saved . ");\n";
        $code .= "}\n";

        return $code;
    }

    /** @param array<string, mixed> $node */
    private function compileWhile(array $node): string
    {
        $id = ++$this->temporaryCounter;
        $code = "\$__twide_previous_loop_exists_{$id} = array_key_exists('loop', \$__twide_context);\n";
        $code .= "\$__twide_previous_loop_{$id} = \$__twide_previous_loop_exists_{$id} ? \$__twide_context['loop'] : null;\n";
        $code .= 'while (' . $this->expressionCompiler->compile($node['condition']) . ") {\n";
        $code .= $this->indent($this->compileNodes($node['body']), 1);
        $code .= "}\n";
        $code .= "if (\$__twide_previous_loop_exists_{$id}) { \$__twide_context['loop'] = \$__twide_previous_loop_{$id}; }"
            . " else { unset(\$__twide_context['loop']); }\n";

        return $code;
    }

    private function saveContextVars(int $id, ?string $loopName, ?string $keyName): string
    {
        $code = '';
        if ($loopName !== null) {
            $code .= "\$__twide_previous_exists_{$id} = array_key_exists(" . var_export($loopName, true) . ", \$__twide_context);\n";
            $code .= "\$__twide_previous_value_{$id} = \$__twide_previous_exists_{$id} ? \$__twide_context[" . var_export($loopName, true) . "] : null;\n";
        }
        if ($keyName !== null) {
            $code .= "\$__twide_previous_key_exists_{$id} = array_key_exists(" . var_export($keyName, true) . ", \$__twide_context);\n";
            $code .= "\$__twide_previous_key_value_{$id} = \$__twide_previous_key_exists_{$id} ? \$__twide_context[" . var_export($keyName, true) . "] : null;\n";
        }
        $code .= "\$__twide_previous_loop_exists_{$id} = array_key_exists('loop', \$__twide_context);\n";
        $code .= "\$__twide_previous_loop_{$id} = \$__twide_previous_loop_exists_{$id} ? \$__twide_context['loop'] : null;\n";

        return $code;
    }

    private function restoreContextVars(int $id, ?string $loopName, ?string $keyName): string
    {
        $code = '';
        if ($loopName !== null) {
            $code .= "if (\$__twide_previous_exists_{$id}) { \$__twide_context[" . var_export($loopName, true) . "] = \$__twide_previous_value_{$id}; }"
                . " else { unset(\$__twide_context[" . var_export($loopName, true) . "]); }\n";
        }
        if ($keyName !== null) {
            $code .= "if (\$__twide_previous_key_exists_{$id}) { \$__twide_context[" . var_export($keyName, true) . "] = \$__twide_previous_key_value_{$id}; }"
                . " else { unset(\$__twide_context[" . var_export($keyName, true) . "]); }\n";
        }
        $code .= "if (\$__twide_previous_loop_exists_{$id}) { \$__twide_context['loop'] = \$__twide_previous_loop_{$id}; }"
            . " else { unset(\$__twide_context['loop']); }\n";

        return $code;
    }

    private function assignLoopMeta(string $index, string $tmp, string $key, string $previousLoop): string
    {
        return "\$__twide_context['loop'] = ["
            . "'index' => {$index} + 1, "
            . "'index0' => {$index}, "
            . "'first' => {$index} === 0, "
            . "'last' => {$index} === count({$tmp}) - 1, "
            . "'length' => count({$tmp}), "
            . "'key' => {$key}, "
            . "'parent' => {$previousLoop}];\n";
    }

    private function indent(string $code, int $levels): string
    {
        if ($code === '') {
            return '';
        }
        $prefix = str_repeat('    ', $levels);
        return $prefix . str_replace("\n", "\n" . $prefix, rtrim($code, "\n")) . "\n";
    }
}
