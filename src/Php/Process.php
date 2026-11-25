<?php
declare(strict_types=1);

namespace FastPhp\Php;

/** A finished child process. */
final class Process
{
    private function __construct(
        public readonly int $exit,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly float $seconds,
    ) {
    }

    /**
     * Runs a command without a shell and waits for it.
     *
     * @param list<string>               $cmd
     * @param array<string, string>|null $env extra variables on top of the current environment
     * @param bool                       $passthru stream stderr to ours (long builds)
     * @param float|null                 $timeout  seconds before the process is killed (exit 124)
     */
    public static function run(array $cmd, ?string $cwd = null, ?array $env = null, bool $passthru = false, ?float $timeout = null): self
    {
        $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => $passthru ? STDERR : ['pipe', 'w']];
        $fullEnv = $env === null ? null : array_merge(getenv(), $env);
        $start = hrtime(true);
        $proc = proc_open($cmd, $spec, $pipes, $cwd, $fullEnv);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Cannot start ' . $cmd[0]);
        }
        $out = '';
        $err = '';
        if ($passthru) {
            $out = (string) stream_get_contents($pipes[1]);
        } else {
            // Read both pipes together, so a child that fills one can't block on it.
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $open = [1 => $pipes[1], 2 => $pipes[2]];
            while ($open) {
                if ($timeout !== null && (hrtime(true) - $start) / 1e9 > $timeout) {
                    proc_terminate($proc, 9);
                    foreach ($pipes as $pipe) {
                        fclose($pipe);
                    }
                    proc_close($proc);
                    return new self(124, $out, $err . "\n[killed after {$timeout} s]", (hrtime(true) - $start) / 1e9);
                }
                $read = array_values($open);
                $w = $e = null;
                if (stream_select($read, $w, $e, 1) === false) {
                    break;
                }
                foreach ($open as $i => $pipe) {
                    $chunk = fread($pipe, 65536);
                    if ($chunk !== false && $chunk !== '') {
                        $i === 1 ? $out .= $chunk : $err .= $chunk;
                    } elseif (feof($pipe)) {
                        unset($open[$i]);
                    }
                }
            }
        }
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        $exit = proc_close($proc);
        return new self($exit, $out, $err, (hrtime(true) - $start) / 1e9);
    }

    public static function quote(array $cmd): string
    {
        return implode(' ', array_map(static fn (string $a) => preg_match('/^[\w.\/=:+,@%-]+$/', $a) ? $a : escapeshellarg($a), $cmd));
    }
}
