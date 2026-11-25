<?php
declare(strict_types=1);

namespace FastPhp\Web;

/**
 * Reads the requests a web scan replays. Three formats:
 *
 *  - .http files, as the JetBrains and VS Code HTTP clients write them: requests separated by
 *    lines starting with ###, each a request line, headers, a blank line and the body;
 *  - a plain list, one request per line: `GET /products?page=2`, or just `/products?page=2`;
 *  - a HAR file exported from the browser's dev tools (requests to other hosts are dropped).
 *
 * Lines starting with # or // are comments.
 */
final class RequestFile
{
    private const METHODS = 'GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS';

    /** @return list<Request> */
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("No request file $path.");
        }
        $requests = self::parse((string) file_get_contents($path), strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'har');
        if (!$requests) {
            throw new \RuntimeException("$path has no requests in it.");
        }
        return $requests;
    }

    /** @return list<Request> */
    public static function parse(string $text, bool $har = false): array
    {
        if ($har || str_starts_with(ltrim($text), '{')) {
            return self::har($text);
        }
        $text = str_replace("\r\n", "\n", $text);
        return preg_match('/^###/m', $text) ? self::blocks($text) : self::lines($text);
    }

    /** @return list<Request> */
    private static function lines(string $text): array
    {
        $out = [];
        foreach (explode("\n", $text) as $n => $line) {
            $line = trim($line);
            if ($line === '' || self::comment($line)) {
                continue;
            }
            if (!preg_match('~^(?:(' . self::METHODS . ')\s+)?(\S+)(?:\s+HTTP/[\d.]+)?$~', $line, $m)) {
                throw new \RuntimeException(sprintf('Line %d isn\'t a request (`GET /path`): "%s". For headers and bodies, separate requests with ### lines (the .http format).', $n + 1, $line));
            }
            $out[] = new Request($m[1] ?: 'GET', Request::target($m[2]));
        }
        return $out;
    }

    /** @return list<Request> */
    private static function blocks(string $text): array
    {
        $out = [];
        foreach (preg_split('/^###.*$/m', $text) as $block) {
            $lines = explode("\n", $block);
            while ($lines && (trim($lines[0]) === '' || self::comment(trim($lines[0])))) {
                array_shift($lines);
            }
            if (!$lines) {
                continue;
            }
            $first = trim(array_shift($lines));
            if (!preg_match('~^(?:(' . self::METHODS . ')\s+)?(\S+)(?:\s+HTTP/[\d.]+)?$~', $first, $m)) {
                throw new \RuntimeException("Not a request line: \"$first\". Each request starts with `METHOD /path`.");
            }
            $headers = [];
            while ($lines && trim($lines[0]) !== '') {
                $h = trim(array_shift($lines));
                if (self::comment($h)) {
                    continue;
                }
                if (!str_contains($h, ':')) {
                    throw new \RuntimeException("Not a header in the request \"$first\": \"$h\". Leave a blank line before the body.");
                }
                [$name, $value] = explode(':', $h, 2);
                $headers[] = [trim($name), trim($value)];
            }
            array_shift($lines);
            $body = rtrim(implode("\n", $lines), "\n");
            $out[] = new Request($m[1] ?: 'GET', Request::target($m[2]), $headers, $body);
        }
        return $out;
    }

    /** @return list<Request> */
    private static function har(string $text): array
    {
        $har = json_decode($text, true);
        if (!is_array($har) || !isset($har['log']['entries'])) {
            throw new \RuntimeException('Not a HAR file: no log.entries in it.');
        }
        $out = [];
        $host = null;
        foreach ($har['log']['entries'] as $e) {
            $r = $e['request'] ?? null;
            if (!is_array($r) || !isset($r['method'], $r['url'])) {
                continue;
            }
            // Requests to other hosts (CDNs, analytics) aren't the app's.
            $h = strtolower((string) parse_url($r['url'], PHP_URL_HOST));
            $host ??= $h;
            if ($h !== $host) {
                continue;
            }
            $headers = [];
            foreach ($r['headers'] ?? [] as $hd) {
                if (isset($hd['name'], $hd['value']) && strtolower($hd['name']) !== 'host') {
                    $headers[] = [(string) $hd['name'], (string) $hd['value']];
                }
            }
            $out[] = new Request(strtoupper($r['method']), Request::target($r['url']), $headers, (string) ($r['postData']['text'] ?? ''));
        }
        return $out;
    }

    private static function comment(string $line): bool
    {
        return str_starts_with($line, '#') || str_starts_with($line, '//');
    }
}
