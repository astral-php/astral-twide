<?php

declare(strict_types=1);

namespace Astral\Twide\Compiler;

use Astral\Twide\Exceptions\TwideException;
use Astral\Twide\Parser\ExpressionParser;
use Astral\Twide\Parser\TemplateParser;

/**
 * Compiles the initial Twide grammar into a PHP closure body.
 *
 * The generated code is intended to be stored in a private cache directory.
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
        $nodes = $this->parser->parse($source);
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
            $type = $node['type'];
            if ($type === 'text') {
                $code .= '$__twide_output .= ' . var_export($node['value'], true) . ";\n";
            } elseif ($type === 'echo') {
                $code .= '$__twide_output .= $__twide_runtime->escape('
                    . $this->expressionCompiler->compile($node['expression']) . ");\n";
            } elseif ($type === 'raw_echo') {
                $code .= '$__twide_output .= $__twide_runtime->raw('
                    . $this->expressionCompiler->compile($node['expression']) . ");\n";
            } elseif ($type === 'if') {
                foreach ($node['branches'] as $index => $branch) {
                    $code .= ($index === 0 ? 'if' : 'elseif') . ' ('
                        . $this->expressionCompiler->compile($branch['condition']) . ") {\n";
                    $code .= $this->indent($this->compileNodes($branch['body']), 1);
                    $code .= "}\n";
                }
                if ($node['else'] !== []) {
                    $code .= "else {\n" . $this->indent($this->compileNodes($node['else']), 1) . "}\n";
                }
            } elseif ($type === 'for') {
                $id = ++$this->temporaryCounter;
                $tmp = '$__twide_items_' . $id;
                $key = '$__twide_key_' . $id;
                $var = '$__twide_item_' . $id;
                $index = '$__twide_index_' . $id;
                $previousExists = '$__twide_previous_exists_' . $id;
                $previousValue = '$__twide_previous_value_' . $id;
                $previousLoopExists = '$__twide_previous_loop_exists_' . $id;
                $previousLoop = '$__twide_previous_loop_' . $id;
                $loopName = (string) $node['variable'];

                $code .= $tmp . ' = $__twide_runtime->iterable('
                    . $this->expressionCompiler->compile($node['expression']) . ");\n";
                $code .= $previousExists . ' = array_key_exists(' . var_export($loopName, true) . ', $__twide_context);' . "\n";
                $code .= $previousValue . ' = ' . $previousExists . ' ? $__twide_context[' . var_export($loopName, true) . '] : null;' . "\n";
                $code .= $previousLoopExists . " = array_key_exists('loop', \$__twide_context);\n";
                $code .= $previousLoop . " = " . $previousLoopExists . " ? \$__twide_context['loop'] : null;\n";
                $code .= $index . " = 0;\n";
                $code .= 'foreach (' . $tmp . ' as ' . $key . ' => ' . $var . ") {\n";
                $code .= '    $__twide_context[' . var_export($loopName, true) . '] = ' . $var . ";\n";
                $code .= "    \$__twide_context['loop'] = ["
                    . "'index' => " . $index . " + 1, "
                    . "'index0' => " . $index . ", "
                    . "'first' => " . $index . " === 0, "
                    . "'last' => " . $index . " === count(" . $tmp . ") - 1, "
                    . "'length' => count(" . $tmp . "), "
                    . "'key' => " . $key . ", "
                    . "'parent' => " . $previousLoop . "];\n";
                $code .= $this->indent($this->compileNodes($node['body']), 1);
                $code .= '    ' . $index . "++;\n";
                $code .= "}\n";
                $code .= 'if (' . $previousExists . ') { $__twide_context[' . var_export($loopName, true) . '] = ' . $previousValue . "; } else { unset(\$__twide_context[" . var_export($loopName, true) . "]); }\n";
                $code .= "if (" . $previousLoopExists . ") { \$__twide_context['loop'] = " . $previousLoop . "; } else { unset(\$__twide_context['loop']); }\n";
            } else {
                throw new TwideException('Unsupported template node "' . $type . '".');
            }
        }
        return $code;
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
