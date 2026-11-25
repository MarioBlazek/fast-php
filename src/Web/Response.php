<?php
declare(strict_types=1);

namespace FastPhp\Web;

final class Response
{
    /** @param array<string, string> $headers lower-case names */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly float $seconds,
    ) {
    }

    /** Parses a whole HTTP/1.x response read until the server closed the connection. */
    public static function parse(string $raw, float $seconds): self
    {
        $split = strpos($raw, "\r\n\r\n");
        if (!preg_match('~^HTTP/\d(?:\.\d)? (\d{3})~', $raw, $m) || $split === false) {
            throw new \RuntimeException('Not an HTTP response: ' . json_encode(substr($raw, 0, 120), JSON_INVALID_UTF8_SUBSTITUTE));
        }
        $headers = [];
        foreach (array_slice(explode("\r\n", substr($raw, 0, $split)), 1) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }
        $body = substr($raw, $split + 4);
        if (str_contains(strtolower($headers['transfer-encoding'] ?? ''), 'chunked')) {
            $body = self::unchunk($body);
        } elseif (isset($headers['content-length']) && strlen($body) > (int) $headers['content-length']) {
            $body = substr($body, 0, (int) $headers['content-length']);
        }
        return new self((int) $m[1], $headers, $body, $seconds);
    }

    private static function unchunk(string $s): string
    {
        $out = '';
        $at = 0;
        while (($eol = strpos($s, "\r\n", $at)) !== false) {
            $size = hexdec(trim(explode(';', substr($s, $at, $eol - $at))[0]));
            if ($size === 0) {
                break;
            }
            $out .= substr($s, $eol + 2, (int) $size);
            $at = $eol + 2 + (int) $size + 2;
        }
        return $out;
    }

    /** What two runs must agree on: the status and the body (not Date and friends). */
    public function fingerprint(): string
    {
        return $this->status . "\n" . $this->body;
    }
}
