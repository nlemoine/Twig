<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Node\Expression\Variable;

use Twig\Compiler;
use Twig\Error\SyntaxError;
use Twig\Node\Expression\AbstractExpression;

final class TemplateVariable extends AbstractExpression
{
    public const RESERVED_NAMES = ['varargs', 'context', 'macros', 'blocks', 'this'];

    public function __construct(string|int|null $name, int $lineno)
    {
        // All names supported by ExpressionParser::parsePrimaryExpression() should be excluded
        if ($name && \in_array(strtolower($name), ['true', 'false', 'none', 'null'])) {
            throw new SyntaxError(\sprintf('You cannot assign a value to "%s".', $name), $lineno);
        }

        if (null !== $name && (is_int($name) || ctype_digit($name))) {
            $name = (int) $name;
        } elseif (in_array($name, self::RESERVED_NAMES)) {
            $name = '_'.$name.'_';
        }

        parent::__construct([], ['name' => $name], $lineno);
    }

    public function getName(Compiler $compiler): string
    {
        if (null === $this->getAttribute('name')) {
            $this->setAttribute('name', $compiler->getVarName());
        }

        return $this->getAttribute('name');
    }

    public function compile(Compiler $compiler): void
    {
        $name = $this->getName($compiler);

        if ('_self' === $name) {
            $compiler->raw('$this');
        } else {
            $compiler
                ->raw('$macros[')
                ->string($name)
                ->raw(']')
            ;
        }
    }
}