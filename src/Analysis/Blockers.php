<?php
declare(strict_types=1);

namespace FastPhp\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Walks one function body: records the user functions it calls and the builtins it uses, and
 * flags everything a pure Rust function can't do the same way (I/O, randomness, globals,
 * objects, dynamic calls). The I/O and non-determinism lists start from fast-anything's
 * describe.php (MIT).
 */
final class Blockers extends NodeVisitorAbstract
{
    private const IO = [
        'fopen', 'fread', 'fwrite', 'fputs', 'fgets', 'fgetc', 'fgetcsv', 'fputcsv', 'fclose', 'feof', 'fflush', 'fseek', 'ftell',
        'ftruncate', 'flock', 'fstat', 'fscanf', 'fpassthru', 'rewind', 'file', 'file_get_contents', 'file_put_contents', 'readfile',
        'unlink', 'rename', 'copy', 'mkdir', 'rmdir', 'touch', 'chmod', 'chown', 'tempnam', 'tmpfile', 'opendir', 'readdir',
        'closedir', 'scandir', 'glob', 'is_file', 'is_dir', 'is_link', 'file_exists', 'is_readable', 'is_writable', 'filesize',
        'filemtime', 'fileatime', 'stat', 'lstat', 'realpath', 'disk_free_space', 'parse_ini_file', 'fsockopen', 'gethostbyname',
        'header', 'header_remove', 'headers_sent', 'http_response_code', 'setcookie', 'setrawcookie', 'mail', 'error_log',
        'syslog', 'openlog', 'printf', 'vprintf', 'var_dump', 'debug_zval_dump', 'flush', 'sleep', 'usleep', 'time_nanosleep',
        'set_time_limit', 'ini_set', 'ini_get', 'ini_restore', 'putenv', 'getenv', 'exec', 'shell_exec', 'system', 'passthru',
        'proc_open', 'popen', 'register_shutdown_function', 'set_error_handler', 'set_exception_handler', 'trigger_error',
        'user_error', 'error_reporting', 'define', 'setlocale', 'date_default_timezone_set', 'spl_autoload_register',
        'memory_get_usage', 'memory_get_peak_usage', 'getmypid', 'getrusage', 'sys_getloadavg', 'move_uploaded_file',
        'is_uploaded_file', 'mt_srand', 'srand', 'libxml_use_internal_errors', 'clearstatcache', 'set_include_path',
    ];
    private const IO_PREFIXES = ['stream_', 'socket_', 'curl_', 'mysqli_', 'pg_', 'sqlite', 'odbc_', 'ob_', 'session_', 'pcntl_',
        'posix_', 'apcu_', 'opcache_', 'gc_', 'ftp_', 'ldap_', 'imap_', 'dns_', 'yaml_emit_file', 'xdebug_'];
    private const NONDET = [
        'rand', 'mt_rand', 'random_int', 'random_bytes', 'lcg_value', 'uniqid', 'time', 'microtime', 'hrtime', 'date', 'gmdate',
        'idate', 'mktime', 'gmmktime', 'strtotime', 'localtime', 'getdate', 'gettimeofday', 'date_create', 'shuffle', 'str_shuffle',
        'array_rand', 'spl_object_id', 'spl_object_hash', 'openssl_random_pseudo_bytes',
    ];
    private const DYNAMIC = [
        'call_user_func', 'call_user_func_array', 'forward_static_call', 'forward_static_call_array', 'func_get_args',
        'func_get_arg', 'func_num_args', 'extract', 'compact', 'get_defined_vars', 'debug_backtrace', 'debug_print_backtrace',
        'constant', 'get_class', 'get_called_class', 'function_exists', 'method_exists', 'class_exists', 'is_callable',
    ];
    private const SUPERGLOBALS = ['GLOBALS', '_GET', '_POST', '_COOKIE', '_FILES', '_SERVER', '_ENV', '_REQUEST', '_SESSION'];

    /** @var array<string, true> */
    private array $found = [];

    public function __construct(private readonly Codebase $cb, private readonly Func $f)
    {
    }

    public function scan(): void
    {
        $traverser = new NodeTraverser($this);
        $traverser->traverse($this->f->node->stmts ?? []);
        foreach (array_keys($this->found) as $why) {
            $this->f->blockers[] = $why;
        }
    }

    private function block(string $why): void
    {
        $this->found[$why] = true;
    }

    public function enterNode(Node $node)
    {
        match (true) {
            $node instanceof Stmt\ClassLike, $node instanceof Stmt\Function_ => null,
            $node instanceof Stmt\Global_ => $this->block('uses global variables'),
            $node instanceof Stmt\Static_ => $this->block('keeps static variables between calls'),
            $node instanceof Expr\Yield_, $node instanceof Expr\YieldFrom => $this->block('is a generator'),
            $node instanceof Expr\Eval_ => $this->block('uses eval()'),
            $node instanceof Expr\Include_ => $this->block('includes files'),
            $node instanceof Expr\Exit_ => $this->block('calls exit()'),
            $node instanceof Stmt\Echo_, $node instanceof Expr\Print_, $node instanceof Stmt\InlineHTML => $this->block('prints output'),
            $node instanceof Expr\ShellExec => $this->block('runs shell commands'),
            $node instanceof Expr\Variable => $this->variable($node),
            $node instanceof Expr\New_ => null, // checked in leaveNode with its parent
            $node instanceof Expr\MethodCall, $node instanceof Expr\NullsafeMethodCall => $this->block('calls methods on objects'),
            $node instanceof Expr\PropertyFetch, $node instanceof Expr\NullsafePropertyFetch => $this->block('reads object properties'),
            $node instanceof Expr\StaticPropertyFetch => $this->block('uses static properties (state shared between calls)'),
            $node instanceof Expr\FuncCall => $this->funcCall($node),
            $node instanceof Expr\StaticCall => $this->staticCall($node),
            $node instanceof Expr\Array_ => $this->callableArray($node),
            $node instanceof Expr\Clone_ => $this->block('clones objects'),
            default => null,
        };
        if ($node instanceof Stmt\Throw_ || $node instanceof Expr\Throw_) {
            // throw new SomeException(...) is fine: ports throw PHP's own exception classes.
            if ($node->expr instanceof Expr\New_) {
                $node->expr->setAttribute('fastphp.thrown', true);
            }
        }
        if ($node instanceof Expr\New_ && !$node->getAttribute('fastphp.thrown')) {
            $this->block('creates objects');
        }
        if ($node instanceof Stmt\ClassLike || $node instanceof Stmt\Function_) {
            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }
        return null;
    }

    private function variable(Expr\Variable $v): void
    {
        if (!is_string($v->name)) {
            $this->block('uses variable variables ($$name)');
        } elseif ($v->name === 'this') {
            $this->block('uses $this');
        } elseif (in_array($v->name, self::SUPERGLOBALS, true)) {
            $this->block("reads \${$v->name}");
        }
    }

    private function funcCall(Expr\FuncCall $call): void
    {
        if (!$call->name instanceof Name) {
            $this->block('calls a function through a variable');
            return;
        }
        $ns = $call->name->getAttribute('namespacedName');
        foreach ([$ns, $call->name] as $candidate) {
            if ($candidate instanceof Name && ($user = $this->cb->find(null, $candidate->toString()))) {
                $this->f->calls[$user->keyOf()] = true;
                $this->stringCallables($call);
                return;
            }
        }
        $name = strtolower($call->name->getLast());
        if (!function_exists($name) || !(new \ReflectionFunction($name))->isInternal()) {
            $this->block("calls $name(), which isn't in the scanned files");
            return;
        }
        if (in_array($name, self::IO, true) || array_filter(self::IO_PREFIXES, static fn ($p) => str_starts_with($name, $p))) {
            $this->block("does I/O or touches process state ($name)");
        } elseif (in_array($name, self::NONDET, true)) {
            $this->block("isn't deterministic ($name)");
        } elseif (in_array($name, self::DYNAMIC, true)) {
            $this->block("uses $name()");
        } elseif (in_array($name, ['print_r', 'var_export'], true) && count($call->args) < 2) {
            $this->block("prints output ($name)");
        }
        $this->f->builtins[$name] = true;
        $this->stringCallables($call);
    }

    /** array_map('normalize', ...) and usort($a, 'cmp'): a string naming a user function. */
    private function stringCallables(Expr\FuncCall $call): void
    {
        foreach ($call->args as $arg) {
            if ($arg instanceof Node\Arg && $arg->value instanceof Scalar\String_) {
                $s = $arg->value->value;
                [$class, $method] = str_contains($s, '::') ? explode('::', $s, 2) : [null, $s];
                if ($class !== null && in_array(strtolower($class), ['self', 'static'], true)) {
                    $class = $this->f->class;
                }
                if ($user = $this->cb->find($class, ltrim($method, '\\'))) {
                    $this->f->calls[$user->keyOf()] = true;
                }
            }
        }
    }

    private function staticCall(Expr\StaticCall $call): void
    {
        if (!$call->class instanceof Name || !$call->name instanceof Node\Identifier) {
            $this->block('calls a static method chosen at runtime');
            return;
        }
        $class = $this->className($call->class);
        if ($class === null) {
            $this->block('calls parent:: methods');
            return;
        }
        if ($user = $this->cb->find($class, $call->name->toString())) {
            $this->f->calls[$user->keyOf()] = true;
            if (!$user->static) {
                $this->block("calls the instance method {$user->display()}");
            }
            return;
        }
        $this->block("calls $class::{$call->name}(), which isn't in the scanned files");
    }

    /** [self::class, 'normalize'] or [Fuzzy::class, 'normalize'] as a callable. */
    private function callableArray(Expr\Array_ $a): void
    {
        if (count($a->items) !== 2 || !$a->items[0] || !$a->items[1]) {
            return;
        }
        [$c, $m] = [$a->items[0]->value, $a->items[1]->value];
        if ($c instanceof Expr\ClassConstFetch && $c->class instanceof Name && $c->name instanceof Node\Identifier
            && strtolower($c->name->toString()) === 'class' && $m instanceof Scalar\String_) {
            $class = $this->className($c->class);
            if ($class !== null && ($user = $this->cb->find($class, $m->value))) {
                $this->f->calls[$user->keyOf()] = true;
            }
        }
    }

    private function className(Name $name): ?string
    {
        return match (strtolower($name->toString())) {
            'self', 'static' => $this->f->class,
            'parent' => null,
            default => $name->toString(),
        };
    }
}
