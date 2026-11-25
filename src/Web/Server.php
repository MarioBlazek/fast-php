<?php
declare(strict_types=1);

namespace FastPhp\Web;

/**
 * PHP's built-in web server (php -S) on a free local port, run the way fast-php runs PHP: no
 * php.ini, opcache and the JIT as asked. PHP_CLI_SERVER_WORKERS forks that many workers, like
 * an FPM pool. stop() takes the workers down too: they outlive a terminated parent.
 */
final class Server
{
    /** @var resource|null */
    private $proc;
    private int $pid;
    public readonly int $port;
    public readonly string $log;

    /**
     * @param list<string>          $php     php and its options, without -S
     * @param array<string, string> $env
     */
    public function __construct(array $php, string $docroot, ?string $router, string $cwd, string $log, array $env = [], int $workers = 1)
    {
        $this->port = self::freePort();
        $this->log = $log;
        $cmd = [...$php, '-S', "127.0.0.1:{$this->port}", '-t', $docroot];
        if ($router !== null) {
            $cmd[] = $router;
        }
        if ($workers > 1) {
            $env['PHP_CLI_SERVER_WORKERS'] = (string) $workers;
        }
        @mkdir(dirname($log), 0777, true);
        file_put_contents($log, '');
        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $cwd, array_merge(getenv(), $env));
        if (!is_resource($proc)) {
            throw new \RuntimeException("Can't start php -S.");
        }
        $this->proc = $proc;
        $this->pid = proc_get_status($proc)['pid'];
        $deadline = microtime(true) + 20;
        while (!Client::listening('127.0.0.1', $this->port)) {
            if (!proc_get_status($proc)['running'] || microtime(true) > $deadline) {
                $this->stop();
                throw new \RuntimeException("php -S didn't start:\n" . self::tail($log) . "\n\nCommand: " . \FastPhp\Php\Process::quote($cmd));
            }
            usleep(20_000);
        }
    }

    public function client(): Client
    {
        return new Client('127.0.0.1', $this->port);
    }

    public function stop(): void
    {
        if ($this->proc === null) {
            return;
        }
        // Workers first: a parent that is gone leaves them running, holding the port.
        $children = trim((string) @shell_exec('pgrep -P ' . $this->pid . ' 2>/dev/null'));
        proc_terminate($this->proc);
        foreach (array_filter(explode("\n", $children)) as $child) {
            @shell_exec('kill ' . (int) $child . ' 2>/dev/null');
        }
        $deadline = microtime(true) + 5;
        while (proc_get_status($this->proc)['running'] && microtime(true) < $deadline) {
            usleep(10_000);
        }
        if (proc_get_status($this->proc)['running']) {
            proc_terminate($this->proc, 9);
        }
        proc_close($this->proc);
        $this->proc = null;
        // Workers write their profile at the end of each request; give the last one a moment to exit.
        $deadline = microtime(true) + 5;
        foreach (array_filter(explode("\n", $children)) as $child) {
            while (microtime(true) < $deadline && trim((string) @shell_exec('kill -0 ' . (int) $child . ' 2>/dev/null && echo up')) === 'up') {
                usleep(10_000);
            }
        }
    }

    public function __destruct()
    {
        $this->stop();
    }

    public static function tail(string $log, int $lines = 15): string
    {
        $all = file(is_file($log) ? $log : '/dev/null', FILE_IGNORE_NEW_LINES) ?: [];
        // php -S logs every connection; the errors are what matter.
        $useful = array_values(array_filter($all, static fn ($l) => !preg_match('/\] (?:\S+ )?(Accepted|Closing)$|\[\d{3}\]: /', $l)));
        return implode("\n", array_slice($useful ?: $all, -$lines));
    }

    private static function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $err);
        if ($s === false) {
            throw new \RuntimeException("No free local port: $err");
        }
        $name = stream_socket_get_name($s, false);
        fclose($s);
        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
