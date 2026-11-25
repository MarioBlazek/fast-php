<?php
declare(strict_types=1);

namespace FastPhp\Web;

/**
 * A small HTTP/1.1 client that replays requests, $concurrency at a time, one connection each
 * (Connection: close). It times each request from connect to the last byte. No dependencies:
 * fast-php can't assume ext-curl.
 */
final class Client
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly bool $tls = false,
        private readonly string $prefix = '',
        private readonly float $timeout = 120.0,
    ) {
    }

    public static function forUrl(string $url): self
    {
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['host']) || !in_array($p['scheme'] ?? '', ['http', 'https'], true)) {
            throw new \RuntimeException("Not an http(s) URL: $url");
        }
        $tls = $p['scheme'] === 'https';
        return new self($p['host'], $p['port'] ?? ($tls ? 443 : 80), $tls, rtrim($p['path'] ?? '', '/'));
    }

    public function address(): string
    {
        return ($this->tls ? 'https' : 'http') . "://{$this->host}:{$this->port}{$this->prefix}";
    }

    /**
     * @param list<Request> $requests
     * @return array{0: list<Response>, 1: float} the responses in request order, and the wall time
     */
    public function replay(array $requests, int $concurrency = 1): array
    {
        $start = hrtime(true);
        $pending = array_keys($requests);
        $active = [];
        $out = [];
        $host = $this->host . (in_array($this->port, [80, 443], true) ? '' : ":{$this->port}");
        while ($pending || $active) {
            while ($pending && count($active) < max(1, $concurrency)) {
                $i = array_shift($pending);
                $t = hrtime(true);
                $s = $this->connect();
                fwrite($s, $requests[$i]->wire($host, $this->prefix));
                stream_set_blocking($s, false);
                $active[$i] = ['sock' => $s, 'buf' => '', 'start' => $t];
            }
            $read = array_column($active, 'sock');
            $w = $e = null;
            if (@stream_select($read, $w, $e, 1) === false) {
                throw new \RuntimeException('Waiting for responses failed.');
            }
            foreach ($active as $i => &$a) {
                $chunk = fread($a['sock'], 1 << 16);
                if ($chunk !== false && $chunk !== '') {
                    $a['buf'] .= $chunk;
                    continue;
                }
                if (feof($a['sock'])) {
                    fclose($a['sock']);
                    $seconds = (hrtime(true) - $a['start']) / 1e9;
                    if ($a['buf'] === '') {
                        throw new \RuntimeException("The server closed the connection without answering {$requests[$i]->label()}.");
                    }
                    $out[$i] = Response::parse($a['buf'], $seconds);
                    unset($active[$i]);
                } elseif ((hrtime(true) - $a['start']) / 1e9 > $this->timeout) {
                    throw new \RuntimeException(sprintf('%s took longer than %d s.', $requests[$i]->label(), $this->timeout));
                }
            }
            unset($a);
        }
        ksort($out);
        return [array_values($out), (hrtime(true) - $start) / 1e9];
    }

    /** @return resource */
    private function connect()
    {
        $s = @stream_socket_client(($this->tls ? 'ssl' : 'tcp') . "://{$this->host}:{$this->port}", $errno, $err, 10);
        if ($s === false) {
            throw new \RuntimeException("Can't connect to {$this->address()}: $err");
        }
        return $s;
    }

    /** Can something accept a connection at host:port right now? */
    public static function listening(string $host, int $port): bool
    {
        $s = @stream_socket_client("tcp://$host:$port", $errno, $err, 0.2);
        if ($s === false) {
            return false;
        }
        fclose($s);
        return true;
    }
}
