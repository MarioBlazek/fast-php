<?php
declare(strict_types=1);

namespace FastPhp\Patch;

use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Marked blocks right after a function's opening brace, on the same line so every line number
 * in the file stays the same. A block is removed byte for byte:
 *
 *     public static function f(array $a): array {/*fast-php:ID*\/ ... /*\/fast-php:ID*\/
 *
 * Insert and strip are exact inverses, which tests/PatcherTest checks; fast-php also compares
 * a hash of the file before and after.
 */
final class Patcher
{
    public static function begin(string $id): string
    {
        return "/*fast-php:$id*/";
    }

    public static function end(string $id): string
    {
        return "/*/fast-php:$id*/";
    }

    /** Offset just past the `{` that opens the body of $class::$name (or function $name). */
    public static function bodyOffset(string $code, ?string $class, string $name): int
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = (new NodeTraverser(new NameResolver()))->traverse($parser->parse($code) ?? []);
        $finder = new NodeFinder();
        $node = null;
        if ($class === null) {
            $node = $finder->findFirst($ast, static fn ($n) => $n instanceof Stmt\Function_ && strcasecmp($n->namespacedName->toString(), $name) === 0);
        } else {
            $cls = $finder->findFirst($ast, static fn ($n) => $n instanceof Stmt\ClassLike && $n->namespacedName !== null && strcasecmp($n->namespacedName->toString(), $class) === 0);
            $node = $cls?->getMethod($name);
        }
        if ($node === null || $node->stmts === null) {
            throw new \RuntimeException('Cannot find ' . ($class ? "$class::" : '') . "$name in the file anymore.");
        }
        // From the function's name: skip the balanced parameter list, then the next `{` opens the body.
        $tokens = $parser->getTokens();
        $depth = 0;
        $seenParams = false;
        foreach ($tokens as $t) {
            if ($t->pos < $node->name->getStartFilePos()) {
                continue;
            }
            if ($t->text === '(') {
                $depth++;
                $seenParams = true;
            } elseif ($t->text === ')') {
                $depth--;
            } elseif ($t->text === '{' && $seenParams && $depth === 0) {
                return $t->pos + 1;
            }
        }
        throw new \RuntimeException("No body found for $name.");
    }

    public static function insert(string $code, ?string $class, string $name, string $id, string $php): string
    {
        if (str_contains($code, self::begin($id))) {
            $code = self::strip($code, $id);
        }
        if (str_contains($php, "\n")) {
            throw new \LogicException('A patch must be a single line.');
        }
        $at = self::bodyOffset($code, $class, $name);
        return substr($code, 0, $at) . self::begin($id) . $php . self::end($id) . substr($code, $at);
    }

    public static function strip(string $code, string $id): string
    {
        $begin = self::begin($id);
        $end = self::end($id);
        while (($a = strpos($code, $begin)) !== false) {
            $b = strpos($code, $end, $a);
            if ($b === false) {
                throw new \RuntimeException("Unterminated fast-php block $id.");
            }
            $code = substr($code, 0, $a) . substr($code, $b + strlen($end));
        }
        return $code;
    }

    /** Removes every fast-php block, whatever its id. */
    public static function stripAll(string $code): string
    {
        while (preg_match('~/\*fast-php:([\w:-]+)\*/~', $code, $m)) {
            $code = self::strip($code, $m[1]);
        }
        return $code;
    }

    public static function has(string $code, string $id): bool
    {
        return str_contains($code, self::begin($id));
    }
}
