<?php

namespace Twig\Tests;

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Extension\AbstractExtension;
use Twig\Loader\ArrayLoader;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\Binary\ConcatBinary;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\Expression\Unary\SpreadUnary;
use Twig\Parser;
use Twig\Source;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;

class ExpressionParserTest extends TestCase
{
    #[DataProvider('getFailingTestsForAssignment')]
    public function testCanOnlyAssignToNames($template)
    {
        $env = new Environment(new ArrayLoader(), ['cache' => false, 'autoescape' => false]);
        $parser = new Parser($env);

        $this->expectException(SyntaxError::class);
        $parser->parse($env->tokenize(new Source($template, 'index')));
    }

    public static function getFailingTestsForAssignment()
    {
        return [
            ['{% set false = "foo" %}'],
            ['{% set FALSE = "foo" %}'],
            ['{% set true = "foo" %}'],
            ['{% set TRUE = "foo" %}'],
            ['{% set none = "foo" %}'],
            ['{% set NONE = "foo" %}'],
            ['{% set null = "foo" %}'],
            ['{% set NULL = "foo" %}'],
            ['{% set 3 = "foo" %}'],
            ['{% set 1 + 2 = "foo" %}'],
            ['{% set "bar" = "foo" %}'],
            ['{% set %}{% endset %}'],
        ];
    }

    #[DataProvider('getTestsForSequence')]
    public function testSequenceExpression($template, $expected)
    {
        $env = new Environment(new ArrayLoader(), ['cache' => false, 'autoescape' => false]);
        $stream = $env->tokenize($source = new Source($template, ''));
        $parser = new Parser($env);
        $expected->setSourceContext($source);

        $this->assertEquals($expected, $parser->parse($stream)->getNode('body')->getNode(0)->getNode('expr'));
    }

    #[DataProvider('getFailingTestsForSequence')]
    public function testSequenceSyntaxError($template)
    {
        $env = new Environment(new ArrayLoader(), ['cache' => false, 'autoescape' => false]);
        $parser = new Parser($env);

        $this->expectException(SyntaxError::class);
        $parser->parse($env->tokenize(new Source($template, 'index')));
    }

    public static function getFailingTestsForSequence()
    {
        return [
            ['{{ [1, "a": "b"] }}'],
            ['{{ {"a": "b", 2} }}'],
            ['{{ {"a"} }}'],
        ];
    }

    public static function getTestsForSequence()
    {
        return [
            // simple sequence
            ['{{ [1, 2] }}', new ArrayExpression([
                new ConstantExpression(0, 1),
                new ConstantExpression(1, 1),

                new ConstantExpression(1, 1),
                new ConstantExpression(2, 1),
            ], 1),
            ],

            // sequence with trailing ,
            ['{{ [1, 2, ] }}', new ArrayExpression([
                new ConstantExpression(0, 1),
                new ConstantExpression(1, 1),

                new ConstantExpression(1, 1),
                new ConstantExpression(2, 1),
            ], 1),
            ],

            // simple mapping
            ['{{ {"a": "b", "b": "c"} }}', new ArrayExpression([
                new ConstantExpression('a', 1),
                new ConstantExpression('b', 1),

                new ConstantExpression('b', 1),
                new ConstantExpression('c', 1),
            ], 1),
            ],

            // mapping with trailing ,
            ['{{ {"a": "b", "b": "c", } }}', new ArrayExpression([
                new ConstantExpression('a', 1),
                new ConstantExpression('b', 1),

                new ConstantExpression('b', 1),
                new ConstantExpression('c', 1),
            ], 1),
            ],

            // mapping in a sequence
            ['{{ [1, {"a": "b", "b": "c"}] }}', new ArrayExpression([
                new ConstantExpression(0, 1),
                new ConstantExpression(1, 1),

                new ConstantExpression(1, 1),
                new ArrayExpression([
                    new ConstantExpression('a', 1),
                    new ConstantExpression('b', 1),

                    new ConstantExpression('b', 1),
                    new ConstantExpression('c', 1),
                ], 1),
            ], 1),
            ],

            // sequence in a mapping
            ['{{ {"a": [1, 2], "b": "c"} }}', new ArrayExpression([
                new ConstantExpression('a', 1),
                new ArrayExpression([
                    new ConstantExpression(0, 1),
                    new ConstantExpression(1, 1),

                    new ConstantExpression(1, 1),
                    new ConstantExpression(2, 1),
                ], 1),
                new ConstantExpression('b', 1),
                new ConstantExpression('c', 1),
            ], 1),
            ],
            ['{{ {a, b} }}', new ArrayExpression([
                new ConstantExpression('a', 1),
                new NameExpression('a', 1),
                new ConstantExpression('b', 1),
                new NameExpression('b', 1),
            ], 1)],

            // sequence with spread operator
            ['{{ [1, 2, ...foo] }}',
                new ArrayExpression([
                    new ConstantExpression(0, 1),
                    new ConstantExpression(1, 1),

                    new ConstantExpression(1, 1),
                    new ConstantExpression(2, 1),

                    new ConstantExpression(2, 1),
                    new SpreadUnary(new NameExpression('foo', 1), 1),
                ], 1)],

            // mapping with spread operator
            ['{{ {"a": "b", "b": "c", ...otherLetters} }}',
                new ArrayExpression([
                    new ConstantExpression('a', 1),
                    new ConstantExpression('b', 1),

                    new ConstantExpression('b', 1),
                    new ConstantExpression('c', 1),

                    new ConstantExpression(0, 1),
                    new SpreadUnary(new NameExpression('otherLetters', 1), 1),
                ], 1)],
        ];
    }

    public function testStringExpressionDoesNotConcatenateTwoConsecutiveStrings()
    {
        $env = new Environment(new ArrayLoader(), ['cache' => false, 'autoescape' => false, 'optimizations' => 0]);
        $stream = $env->tokenize(new Source('{{ "a" "b" }}', 'index'));
        $parser = new Parser($env);

        $this->expectException(SyntaxError::class);
        $parser->parse($stream);
    }

    #[DataProvider('getTestsForString')]
    public function testStringExpression($template, $expected)
    {
        $env = new Environment(new ArrayLoader(), ['cache' => false, 'autoescape' => false, 'optimizations' => 0]);
        $stream = $env->tokenize($source = new Source($template, ''));
        $parser = new Parser($env);
        $expected->setSourceContext($source);

        $this->assertEquals($expected, $parser->parse($stream)->getNode('body')->getNode(0)->getNode('expr'));
    }

    public static function getTestsForString()
    {
        return [
            [
                '{{ "foo #{bar}" }}', new ConcatBinary(
                    new ConstantExpression('foo ', 1),
                    new NameExpression('bar', 1),
                    1
                ),
            ],
            [
                '{{ "foo #{bar} baz" }}', new ConcatBinary(
                    new ConcatBinary(
                        new ConstantExpression('foo ', 1),
                        new NameExpression('bar', 1),
                        1
                    ),
                    new ConstantExpression(' baz', 1),
                    1
                ),
            ],

            [
                '{{ "foo #{"foo #{bar} baz"} baz" }}', new ConcatBinary(
                    new ConcatBinary(
                        new ConstantExpression('foo ', 1),
                        new ConcatBinary(
                            new ConcatBinary(
                                new ConstantExpression('foo ', 1),
                                new NameExpression('bar', 1),
                                1
                            ),
                            new ConstantExpression(' baz', 1),
                            1
                        ),
                        1
                    ),
                    new ConstantExpression(' baz', 1),
                    1
                ),
            ],
        ];
    }

    public function testMacroDefinitionDoesNotSupportNonNameVariableName()
    {
        $env = new Environment(new ArrayLoader(), ['cache' => false, 'autoescape' => false]);
        $parser = new Parser($env);

        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage('An argument must be a name. Unexpected token "string" of value "a" ("name" expected) in "index" at line 1.');

        $parser->parse($env->tokenize(new Source('{% macro foo("a") %}{% endmacro %}', 'index')));
    }

    #[DataProvider('getMacroDefinitionDoesNotSupportNonConstantDefaultValues')]
    public function testMacroDefinitionDoesNotSupportNonConstantDefaultValues($template)
    {
        $env = new Environment(new ArrayLoader(), ['cache' => false, 'autoescape' => false]);
        $parser = new Parser($env);

        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage('A default value for an argument must be a constant (a boolean, a string, a number, a sequence, or a mapping) in "index" at line 1');

        $parser->parse($env->tokenize(new Source($template, 'index')));
    }

    public static function getMacroDefinitionDoesNotSupportNonConstantDefaultValues()
    {
        return [
            ['{% macro foo(name = "a #{foo} a") %}{% endmacro %}'],
            ['{% macro foo(name = [["b", "a #{foo} a"]]) %}{% endmacro %}'],
        ];
    }

    #[DataProvider('getMacroDefinitionSupportsConstantDefaultValues')]
    public function testMacroDefinitionSupportsConstantDefaultValues($template)
    {
        $env = new Environment(new ArrayLoader(), ['cache' => false, 'autoescape' => false]);
        $parser = new Parser($env);

        $parser->parse($env->tokenize(new Source($template, 'index')));

        // add a dummy assertion here to satisfy PHPUnit, the only thing we want to test is that the code above
        // can be executed without throwing any exceptions
        $this->addToAssertionCount(1);
    }

    public static function getMacroDefinitionSupportsConstantDefaultValues()
    {
        return [
            ['{% macro foo(name = "aa") %}{% endmacro %}'],
            ['{% macro foo(name = 12) %}{% endmacro %}'],
            ['{% macro foo(name = true) %}{% endmacro %}'],
            ['{% macro foo(name = ["a"]) %}{% endmacro %}'],
            ['{% macro foo(name = [["a"]]) %}{% endmacro %}'],
            ['{% macro foo(name = {a: "a"}) %}{% endmacro %}'],
            ['{% macro foo(name = {a: {b: "a"}}) %}{% endmacro %}'],
        ];
    }

    public function testUnknownFunction()
    {
        $env = new Environment(new ArrayLoader(), ['cache' => false, 'autoescape' => false]);
        $parser = new Parser($env);

        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage('Unknown "cycl" function. Did you mean "cycle" in "index" at line 1?');

        $parser->parse($env->tokenize(new Source('{{ cycl() }}', 'index')));
    }

    public function testUnknownFunctionWithoutSuggestions()
    {
        $env = new Environment(new ArrayLoader(), ['cache' => false, 'autoescape' => false]);
        $parser = new Parser($env);

        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage('Unknown "foobar" function in "index" at line 1.');

        $parser->parse($env->tokenize(new Source('{{ foobar() }}', 'index')));
    }

    public function testUnknownFilter()
    {
        $env = new Environment(new ArrayLoader(), ['cache' => false, 'autoescape' => false]);
        $parser = new Parser($env);

        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage('Unknown "lowe" filter. Did you mean "lower" in "index" at line 1?');

        $parser->parse($env->tokenize(new Source('{{ 1|lowe }}', 'index')));
    }

    public function testUnknownFilterWithoutSuggestions()
    {
        $env = new Environment(new ArrayLoader(), ['cache' => false, 'autoescape' => false]);
        $parser = new Parser($env);

        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage('Unknown "foobar" filter in "index" at line 1.');

        $parser->parse($env->tokenize(new Source('{{ 1|foobar }}', 'index')));
    }

    public function testUnknownTest()
    {
        $env = new Environment(new ArrayLoader(), ['cache' => false, 'autoescape' => false]);
        $parser = new Parser($env);
        $stream = $env->tokenize(new Source('{{ 1 is nul }}', 'index'));

        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage('Unknown "nul" test. Did you mean "null" in "index" at line 1');

        $parser->parse($stream);
    }

    public function testUnknownTestWithoutSuggestions()
    {
        $env = new Environment(new ArrayLoader(), ['cache' => false, 'autoescape' => false]);
        $parser = new Parser($env);

        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage('Unknown "foobar" test in "index" at line 1.');

        $parser->parse($env->tokenize(new Source('{{ 1 is foobar }}', 'index')));
    }

    public function testCompiledCodeForDynamicTest()
    {
        $env = new Environment(new ArrayLoader(['index' => '{{ "a" is foo_foo_bar_bar }}']), ['cache' => false, 'autoescape' => false]);
        $env->addExtension(new class() extends AbstractExtension {
            public function getTests(): array
            {
                return [
                    new TwigTest('*_foo_*_bar', function ($foo, $bar, $a) {}),
                ];
            }
        });

        $this->assertStringContainsString('$this->env->getTest(\'*_foo_*_bar\')->getCallable()("foo", "bar", "a")', $env->compile($env->parse($env->tokenize(new Source($env->getLoader()->getSourceContext('index')->getCode(), 'index')))));
    }

    public function testCompiledCodeForDynamicFunction()
    {
        $env = new Environment(new ArrayLoader(['index' => '{{ foo_foo_bar_bar("a") }}']), ['cache' => false, 'autoescape' => false]);
        $env->addExtension(new class() extends AbstractExtension {
            public function getFunctions(): array
            {
                return [
                    new TwigFunction('*_foo_*_bar', function ($foo, $bar, $a) {}),
                ];
            }
        });

        $this->assertStringContainsString('$this->env->getFunction(\'*_foo_*_bar\')->getCallable()("foo", "bar", "a")', $env->compile($env->parse($env->tokenize(new Source($env->getLoader()->getSourceContext('index')->getCode(), 'index')))));
    }

    public function testCompiledCodeForDynamicFilter()
    {
        $env = new Environment(new ArrayLoader(['index' => '{{ "a"|foo_foo_bar_bar }}']), ['cache' => false, 'autoescape' => false]);
        $env->addExtension(new class() extends AbstractExtension {
            public function getFilters(): array
            {
                return [
                    new TwigFilter('*_foo_*_bar', function ($foo, $bar, $a) {}),
                ];
            }
        });

        $this->assertStringContainsString('$this->env->getFilter(\'*_foo_*_bar\')->getCallable()("foo", "bar", "a")', $env->compile($env->parse($env->tokenize(new Source($env->getLoader()->getSourceContext('index')->getCode(), 'index')))));
    }

    public function testTwoWordTestPrecedence()
    {
        // a "empty element" test must have precedence over "empty"
        $env = new Environment(new ArrayLoader(), ['cache' => false, 'autoescape' => false]);
        $env->addTest(new TwigTest('empty element', 'foo'));
        $parser = new Parser($env);

        $parser->parse($env->tokenize(new Source('{{ 1 is empty element }}', 'index')));
        $this->expectNotToPerformAssertions();
    }
}
