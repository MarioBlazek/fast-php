<?php
declare(strict_types=1);

namespace FastPhp\Analysis;

use PhpParser\Node;

/** A function or method found in the source, with what stands in the way of porting it. */
final class Func
{
    /** @var list<string> why this function can't be ported, own body only */
    public array $blockers = [];
    /** @var array<string, true> keys (see key()) of user functions and methods it calls */
    public array $calls = [];
    /** @var array<string, true> builtin functions it calls, lowercase */
    public array $builtins = [];

    /**
     * @param list<array{name: string, type: ?string, default: ?string, byRef: bool, variadic: bool}> $params
     */
    public function __construct(
        public readonly ?string $class,
        public readonly string $name,
        public readonly bool $static,
        public readonly string $file,
        public readonly int $line,
        public readonly array $params,
        public readonly ?string $returnType,
        public readonly Node\Stmt\Function_|Node\Stmt\ClassMethod $node,
    ) {
    }

    /** Lowercase, like PHP's function table: "fuzzy::findduplicates", "app\\slugify". */
    public static function key(?string $class, string $name): string
    {
        return strtolower(($class !== null ? $class . '::' : '') . $name);
    }

    public function keyOf(): string
    {
        return self::key($this->class, $this->name);
    }

    /** Fuzzy::findDuplicates, App\slugify */
    public function display(): string
    {
        return ($this->class !== null ? $this->class . '::' : '') . $this->name;
    }

    /** Fuzzy_findDuplicates, App_slugify: the port's id and the native function's name. */
    public function id(): string
    {
        return str_replace(['\\', '::'], '_', $this->display());
    }
}
