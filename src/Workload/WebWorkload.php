<?php
declare(strict_types=1);

namespace FastPhp\Workload;

use FastPhp\Php\Php;
use FastPhp\Project;
use FastPhp\Web\Client;
use FastPhp\Web\Request;
use FastPhp\Web\RequestFile;
use FastPhp\Web\Response;
use FastPhp\Web\Server;

/**
 * Web requests from a file, replayed against PHP's built-in server: `php -S` with the app's
 * document root and, optionally, a router script. With workers > 1, php -S forks that many
 * processes (PHP_CLI_SERVER_WORKERS) and the requests go out that many at a time.
 */
final class WebWorkload extends Workload
{
    /** @var list<Request>|null */
    private ?array $requests = null;

    /** @param list<string> $php */
    public function __construct(
        public readonly string $requestFile,
        public readonly string $docroot,
        public readonly ?string $router = null,
        public readonly array $php = [],
        public readonly int $workers = 1,
        public readonly int $repeat = 1,
    ) {
    }

    /**
     * From the command after `--`, as the app's dev server would start:
     * php -S localhost:8000 -t public [router.php]. Without it: -t public (if there is one).
     *
     * @param list<string> $tokens
     */
    public static function parse(array $tokens, string $requestFile, Project $project, int $workers, int $repeat): self
    {
        if (!is_file($project->abs($requestFile))) {
            throw new \RuntimeException("No request file $requestFile.");
        }
        [$php, $rest] = CliWorkload::options($tokens);
        $docroot = null;
        $router = null;
        while ($rest) {
            $t = array_shift($rest);
            if ($t === '-S') {
                array_shift($rest); // fast-php picks its own free port
            } elseif ($t === '-t') {
                $docroot = array_shift($rest);
            } elseif ($router === null && !str_starts_with($t, '-')) {
                $router = $t;
            } else {
                throw new \RuntimeException("Unexpected \"$t\" in the server command. Give it as: -- php -S localhost:8000 -t public [router.php]");
            }
        }
        $docroot ??= is_dir($project->abs('public')) ? 'public' : '.';
        if (!is_dir($project->abs($docroot))) {
            throw new \RuntimeException("No document root $docroot.");
        }
        if ($router !== null && !is_file($project->abs($router))) {
            throw new \RuntimeException("No router script $router.");
        }
        $rel = static fn (string $p) => $project->rel((string) realpath($project->abs($p)));
        $w = new self($rel($requestFile), $rel($docroot) === $project->root ? '.' : $rel($docroot), $router !== null ? $rel($router) : null, $php, max(1, $workers), max(1, $repeat));
        $w->requests($project); // fails early on a bad file
        return $w;
    }

    public static function fromState(array $s): self
    {
        return new self($s['requests'], $s['docroot'], $s['router'] ?? null, $s['php'] ?? [], $s['workers'] ?? 1, $s['repeat'] ?? 1);
    }

    public function toState(): array
    {
        return ['type' => 'web', 'requests' => $this->requestFile, 'docroot' => $this->docroot, 'router' => $this->router,
            'php' => $this->php, 'workers' => $this->workers, 'repeat' => $this->repeat];
    }

    public function describe(): string
    {
        $n = count($this->requests ?? []);
        return sprintf('%s%s against php -S -t %s%s%s', $this->requestFile, $n ? " ($n requests" . ($this->repeat > 1 ? " × {$this->repeat}" : '') . ')' : '',
            $this->docroot, $this->router !== null ? " {$this->router}" : '', $this->workers > 1 ? ", {$this->workers} workers" : '');
    }

    /** The requests of one pass (the file, repeated). @return list<Request> */
    public function requests(Project $project): array
    {
        $this->requests ??= RequestFile::load($project->abs($this->requestFile));
        $all = [];
        for ($i = 0; $i < $this->repeat; $i++) {
            array_push($all, ...$this->requests);
        }
        return $all;
    }

    /**
     * @param list<string>          $extensions
     * @param list<string>          $extra
     * @param array<string, string> $env
     */
    public function start(Php $php, Project $project, array $extensions, bool $jit, array $extra = [], array $env = [], string $name = 'server'): Server
    {
        $cmd = $php->base();
        foreach ($extensions as $ext) {
            array_push($cmd, '-d', 'extension=' . $ext);
        }
        array_push($cmd, ...($jit ? Php::JIT : Php::NO_JIT), ...$this->php, ...$extra);
        return new Server($cmd, $project->abs($this->docroot), $this->router !== null ? $project->abs($this->router) : null,
            $project->root, $project->path('tmp', "$name.log"), $env, $this->workers);
    }

    /** Replays every request once against $server (or a URL's client). */
    public function pass(Project $project, Client $client, ?Server $server = null, bool $allowErrors = false): Run
    {
        $requests = $this->requests($project);
        [$responses, $seconds] = $client->replay($requests, $this->workers);
        if (!$allowErrors) {
            foreach ($responses as $i => $r) {
                if ($r->status >= 500) {
                    throw new \RuntimeException(sprintf("%s answered %d:\n%s%s", $requests[$i]->label(), $r->status, self::excerpt($r->body),
                        $server !== null ? "\n\nphp -S log:\n" . Server::tail($server->log) : ''));
                }
            }
        }
        return new Run($seconds, array_map(static fn (Response $r) => $r->fingerprint(), $responses),
            array_map(static fn (Request $r) => $r->label(), $requests), array_map(static fn (Response $r) => $r->seconds, $responses));
    }

    public function once(Php $php, Project $project, array $extensions, bool $jit, array $extra = [], array $env = [], ?\Closure $warmed = null): Run
    {
        $server = $this->start($php, $project, $extensions, $jit, $extra, $env);
        try {
            if ($warmed !== null) {
                $this->warm($project, $server->client(), $server);
                $warmed();
            }
            return $this->pass($project, $server->client(), $server);
        } finally {
            $server->stop();
        }
    }

    public function best(Php $php, Project $project, array $extensions, bool $jit, int $runs): Run
    {
        $server = $this->start($php, $project, $extensions, $jit);
        try {
            $this->warm($project, $server->client(), $server);
            $best = null;
            for ($i = 0; $i < max(1, $runs); $i++) {
                $r = $this->pass($project, $server->client(), $server);
                if ($best === null || $r->seconds < $best->seconds) {
                    $best = $r;
                }
            }
            return $best;
        } finally {
            $server->stop();
        }
    }

    /**
     * Replays the requests twice untimed. Opcache compiles on the first request of each script,
     * and the tracing JIT compiles a loop once it has run often enough in each worker; one
     * pass shared between workers isn't always enough for that.
     */
    public function warm(Project $project, Client $client, ?Server $server = null, bool $allowErrors = false): void
    {
        $this->pass($project, $client, $server, $allowErrors);
        $this->pass($project, $client, $server, $allowErrors);
    }

    public static function excerpt(string $body): string
    {
        $text = trim(strip_tags($body));
        return strlen($text) > 600 ? substr($text, 0, 600) . ' …' : $text;
    }
}
