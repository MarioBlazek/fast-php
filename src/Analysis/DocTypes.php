<?php
declare(strict_types=1);

namespace FastPhp\Analysis;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\Type;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

/**
 * Reads @param and @return types (phpstan/phpdoc-parser) into the shapes Port\Types works
 * with. PHPDoc isn't enforced by PHP, so these only narrow types where the generated glue has a
 * fallback: a value that doesn't fit makes the original PHP run.
 *
 * Extra shapes on top of Types': 'str' (a string, encoding unknown), ['map', V] (any keys),
 * ['arr', V] (list or map, from T[] and array<V>), ['tuple', [A, B, ...]].
 */
final class DocTypes
{
    private static ?PhpDocParser $parser = null;
    private static ?Lexer $lexer = null;

    /** @return array{params: array<string, string|array>, return: string|array|null} */
    public static function read(?string $doc): array
    {
        $out = ['params' => [], 'return' => null];
        if ($doc === null || !str_contains($doc, '@')) {
            return $out;
        }
        if (self::$parser === null) {
            $config = new ParserConfig([]);
            self::$lexer = new Lexer($config);
            $const = new ConstExprParser($config);
            self::$parser = new PhpDocParser($config, new TypeParser($config, $const), $const);
        }
        try {
            /** @var PhpDocNode $node */
            $node = self::$parser->parse(new TokenIterator(self::$lexer->tokenize($doc)));
        } catch (\Throwable) {
            return $out;
        }
        foreach (['@param', '@phpstan-param', '@psalm-param'] as $tag) {
            foreach ($node->getParamTagValues($tag) as $p) {
                $out['params'][ltrim($p->parameterName, '$')] = self::shape($p->type);
            }
        }
        foreach (['@return', '@phpstan-return', '@psalm-return'] as $tag) {
            foreach ($node->getReturnTagValues($tag) as $r) {
                $out['return'] = self::shape($r->type);
            }
        }
        return $out;
    }

    public static function shape(Type\TypeNode $t): string|array
    {
        if ($t instanceof Type\NullableTypeNode) {
            return ['?', self::shape($t->type)];
        }
        if ($t instanceof Type\UnionTypeNode) {
            $parts = $t->types;
            $nullable = false;
            $rest = [];
            foreach ($parts as $p) {
                if ($p instanceof Type\IdentifierTypeNode && strtolower($p->name) === 'null') {
                    $nullable = true;
                } else {
                    $rest[] = self::shape($p);
                }
            }
            $one = count($rest) === 1 || ($rest && count(array_unique(array_map('serialize', $rest))) === 1) ? $rest[0] : 'mixed';
            return $nullable ? ['?', $one] : $one;
        }
        if ($t instanceof Type\IdentifierTypeNode) {
            return match (strtolower($t->name)) {
                'int', 'integer', 'positive-int', 'negative-int', 'non-negative-int', 'non-positive-int', 'non-zero-int' => 'int',
                'float', 'double' => 'float',
                'bool', 'boolean', 'true', 'false' => 'bool',
                'string', 'non-empty-string', 'numeric-string', 'lowercase-string', 'uppercase-string', 'non-falsy-string', 'truthy-string', 'literal-string', 'class-string' => 'str',
                'list', 'non-empty-list' => ['list', 'mixed'],
                'array', 'non-empty-array' => ['arr', 'mixed'],
                'null' => 'null',
                default => 'mixed',
            };
        }
        if ($t instanceof Type\GenericTypeNode) {
            $name = strtolower($t->type->name);
            $args = $t->genericTypes;
            return match (true) {
                $name === 'int' => 'int', // int<0, max>
                in_array($name, ['list', 'non-empty-list'], true) => ['list', self::shape($args[0])],
                in_array($name, ['array', 'non-empty-array', 'iterable'], true) && count($args) === 1 => ['arr', self::shape($args[0])],
                in_array($name, ['array', 'non-empty-array'], true) && count($args) === 2 => ['map', self::shape($args[1])],
                default => 'mixed',
            };
        }
        if ($t instanceof Type\ArrayTypeNode) {
            return ['arr', self::shape($t->type)];
        }
        if ($t instanceof Type\ArrayShapeNode) {
            // array{int, int} or array{0: int, 1: int}: a short fixed-length list is a tuple.
            $items = [];
            foreach ($t->items as $i => $item) {
                $key = $item->keyName;
                $isIndex = $key === null || ($key instanceof ConstExprIntegerNode && (int) $key->value === $i);
                if (!$isIndex || $item->optional) {
                    return ['map', 'mixed'];
                }
                $items[] = self::shape($item->valueType);
            }
            return $t->sealed && count($items) >= 2 && count($items) <= 4 ? ['tuple', $items] : ['list', 'mixed'];
        }
        return 'mixed';
    }
}
