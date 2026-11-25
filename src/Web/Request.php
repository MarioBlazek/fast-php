<?php
declare(strict_types=1);

namespace FastPhp\Web;

/** One HTTP request to replay: a method, a path with its query, headers and a body. */
final class Request
{
    /** Headers never replayed: the client sets them, or they would change the response's encoding. */
    public const DROPPED = ['host', 'content-length', 'connection', 'keep-alive', 'transfer-encoding', 'te', 'upgrade',
        'accept-encoding', 'proxy-connection', 'http2-settings'];

    /**
     * @param list<array{0: string, 1: string}> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $target,
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }

    /** Takes the path and query of an absolute URL; keeps a path as it is. */
    public static function target(string $url): string
    {
        if (preg_match('~^https?://[^/?#]*([^#]*)~i', $url, $m)) {
            $url = $m[1];
        }
        $url = preg_replace('/#.*$/', '', $url);
        return $url === '' ? '/' : ($url[0] === '/' ? $url : '/' . $url);
    }

    /** The request as bytes on the wire, for the server at $host (host:port). */
    public function wire(string $host, string $prefix = ''): string
    {
        $out = "{$this->method} " . $prefix . $this->target . " HTTP/1.1\r\n";
        $hasHost = false;
        foreach ($this->headers as [$name, $value]) {
            $lower = strtolower($name);
            if ($lower === 'host') {
                $hasHost = true; // a Host the request file names explicitly (virtual hosts)
                $out .= "Host: $value\r\n";
                continue;
            }
            if (in_array($lower, self::DROPPED, true) || str_starts_with($name, ':')) {
                continue;
            }
            $out .= "$name: $value\r\n";
        }
        if (!$hasHost) {
            $out .= "Host: $host\r\n";
        }
        if ($this->body !== '' || in_array($this->method, ['POST', 'PUT', 'PATCH'], true)) {
            $out .= 'Content-Length: ' . strlen($this->body) . "\r\n";
        }
        return $out . "Connection: close\r\n\r\n" . $this->body;
    }

    public function label(): string
    {
        return "{$this->method} {$this->target}";
    }
}
