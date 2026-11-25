<?php
declare(strict_types=1);

namespace FastPhp\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * The functions and static methods of a set of files, what each one calls, and why a function
 * can't be ported (its own blockers; Ranker adds the ones inherited from helpers).
 */
final class Codebase
{
    /** @var array<string, Func> by Func::key() */
    public array $funcs = [];
    /** @var array<string, string> file => source */
    public array $sources = [];

    /** @param list<string> $files */
    public static function load(array $files): self
    {
        $cb = new self();
        $parser = (new ParserFactory())->createForHostVersion();
        $printer = new Standard();
        $asts = [];
        foreach (array_unique($files) as $file) {
            // Analyse the code as written, without fast-php's own prologues.
            $code = \FastPhp\Patch\Patcher::stripAll((string) file_get_contents($file));
            try {
                $ast = $parser->parse($code) ?? [];
            } catch (\PhpParser\Error) {
                continue;
            }
            $traverser = new NodeTraverser(new NameResolver());
            $asts[$file] = $traverser->traverse($ast);
            $cb->sources[$file] = $code;
        }
        foreach ($asts as $file => $ast) {
            $cb->collect($file, $ast, $printer);
        }
        foreach ($cb->funcs as $f) {
            (new Blockers($cb, $f))->scan();
        }
        return $cb;
    }

    /** @param list<Node> $ast */
    private function collect(string $file, array $ast, Standard $printer): void
    {
        $finder = new NodeFinder();
        foreach ($finder->findInstanceOf($ast, Stmt\Function_::class) as $fn) {
            $this->add(new Func(null, $fn->namespacedName->toString(), true, $file, $fn->getStartLine(), $this->params($fn, $printer), $this->type($fn->returnType, $printer), $fn), $fn);
        }
        foreach ($finder->findInstanceOf($ast, Stmt\ClassLike::class) as $class) {
            if ($class->namespacedName === null) {
                continue; // anonymous class
            }
            foreach ($class->getMethods() as $m) {
                if ($m->stmts === null) {
                    continue; // abstract or interface
                }
                $f = new Func($class->namespacedName->toString(), $m->name->toString(), $m->isStatic(), $file, $m->getStartLine(), $this->params($m, $printer), $this->type($m->returnType, $printer), $m);
                if ($class instanceof Stmt\Trait_) {
                    $f->blockers[] = 'is a trait method';
                } elseif (!$m->isStatic()) {
                    $f->blockers[] = 'is an instance method (planned for Phase 2: readonly $this)';
                }
                $this->add($f, $m);
            }
        }
    }

    private function add(Func $f, Stmt\Function_|Stmt\ClassMethod $node): void
    {
        if ($node->byRef) {
            $f->blockers[] = 'returns by reference';
        }
        foreach ($f->params as $p) {
            if ($p['byRef']) {
                $f->blockers[] = "takes \${$p['name']} by reference";
            }
            if ($p['variadic']) {
                $f->blockers[] = "takes ...\${$p['name']}";
            }
            if ($p['type'] !== null && self::isObjectType($p['type'])) {
                $f->blockers[] = "takes an object (\${$p['name']}: {$p['type']})";
            }
        }
        $ret = $f->returnType;
        if ($ret === 'void' || $ret === 'never') {
            $f->blockers[] = "returns $ret (nothing to port: a pure function must return its work)";
        } elseif ($ret !== null && self::isObjectType($ret)) {
            $f->blockers[] = "returns an object ($ret)";
        }
        $this->funcs[$f->keyOf()] = $f;
    }

    /** True when a type admits nothing but objects/callables. */
    public static function isObjectType(string $type): bool
    {
        $scalar = ['int', 'float', 'string', 'bool', 'array', 'mixed', 'null', 'false', 'true', 'iterable'];
        foreach (preg_split('/[|&()]/', ltrim($type, '?'), -1, PREG_SPLIT_NO_EMPTY) as $part) {
            if (in_array(strtolower($part), $scalar, true)) {
                return false;
            }
        }
        return true;
    }

    /** @return list<array{name: string, type: ?string, default: ?string, byRef: bool, variadic: bool}> */
    private function params(Stmt\Function_|Stmt\ClassMethod $fn, Standard $printer): array
    {
        $out = [];
        foreach ($fn->params as $p) {
            $out[] = [
                'name' => $p->var instanceof Expr\Variable && is_string($p->var->name) ? $p->var->name : '_',
                'type' => $this->type($p->type, $printer),
                'default' => $p->default !== null ? $printer->prettyPrintExpr($p->default) : null,
                'byRef' => $p->byRef,
                'variadic' => $p->variadic,
            ];
        }
        return $out;
    }

    private function type(?Node $type, Standard $printer): ?string
    {
        if ($type === null) {
            return null;
        }
        $s = $printer->prettyPrint([$type]);
        return ltrim($s, '\\');
    }

    public function find(?string $class, string $name): ?Func
    {
        return $this->funcs[Func::key($class, $name)] ?? null;
    }

    /** The function's source text, exactly as written. */
    public function source(Func $f): string
    {
        $node = $f->node;
        $start = $node->getStartFilePos();
        $doc = $node->getDocComment();
        if ($doc !== null) {
            $start = $doc->getStartFilePos();
        }
        $code = $this->sources[$f->file];
        $lineStart = strrpos(substr($code, 0, $start), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $text = substr($code, $lineStart, $node->getEndFilePos() - $lineStart + 1);
        // Drop the indentation the class gave it.
        $lines = explode("\n", $text);
        $indent = min(array_map(static fn ($l) => trim($l) === '' ? PHP_INT_MAX : strspn($l, " \t"), $lines));
        return implode("\n", array_map(static fn ($l) => substr($l, min($indent, strlen($l))), $lines));
    }

    /** All functions reachable from $f through static calls, $f excluded. @return array<string, Func> */
    public function reachable(Func $f): array
    {
        $seen = [];
        $todo = array_keys($f->calls);
        while ($todo) {
            $k = array_pop($todo);
            if (isset($seen[$k]) || $k === $f->keyOf() || !isset($this->funcs[$k])) {
                continue;
            }
            $seen[$k] = $this->funcs[$k];
            array_push($todo, ...array_keys($this->funcs[$k]->calls));
        }
        return $seen;
    }

    /** Is a file nothing but declarations (safe to require on its own)? */
    public function declarationsOnly(string $file): bool
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($this->sources[$file] ?? (string) file_get_contents($file)) ?? [];
        $ok = static function (array $stmts) use (&$ok): bool {
            foreach ($stmts as $s) {
                if ($s instanceof Stmt\Namespace_) {
                    if (!$ok($s->stmts)) {
                        return false;
                    }
                } elseif ($s instanceof Stmt\ClassLike) {
                    if (($s instanceof Stmt\Class_ && ($s->extends !== null || $s->implements)) || $s->getTraitUses()) {
                        return false; // needs other files
                    }
                } elseif (!($s instanceof Stmt\Function_ || $s instanceof Stmt\Use_ || $s instanceof Stmt\GroupUse
                    || $s instanceof Stmt\Declare_ || $s instanceof Stmt\Const_ || $s instanceof Stmt\Nop)) {
                    return false;
                }
            }
            return true;
        };
        return $ok($ast);
    }
}
