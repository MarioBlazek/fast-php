<?php
declare(strict_types=1);

namespace FastPhp\Tests;

use FastPhp\Web\Request;
use FastPhp\Web\RequestFile;
use FastPhp\Web\Response;
use FastPhp\Web\Server;
use PHPUnit\Framework\TestCase;

final class WebTest extends TestCase
{
    public function testHttpFileBlocks(): void
    {
        $reqs = RequestFile::parse(<<<'HTTP'
            # comment before the first request
            ### Listing
            GET http://shop.test/products?page=2#top
            Accept: text/html

            ### Checkout
            POST /cart HTTP/1.1
            Content-Type: application/json
            // a comment among the headers

            {"items": [1, 2]}
            {"more": true}

            ###
            /plain
            HTTP);
        self::assertCount(3, $reqs);
        self::assertSame(['GET', '/products?page=2', [['Accept', 'text/html']], ''], [$reqs[0]->method, $reqs[0]->target, $reqs[0]->headers, $reqs[0]->body]);
        self::assertSame("{\"items\": [1, 2]}\n{\"more\": true}", $reqs[1]->body);
        self::assertSame([['Content-Type', 'application/json']], $reqs[1]->headers);
        self::assertSame('GET /plain', $reqs[2]->label());
    }

    public function testOneRequestPerLine(): void
    {
        $reqs = RequestFile::parse("/a\nPOST /b\n# skip\n\nhttps://x.test/c?d=1\n");
        self::assertSame(['GET /a', 'POST /b', 'GET /c?d=1'], array_map(static fn (Request $r) => $r->label(), $reqs));
        $this->expectExceptionMessage('### lines');
        RequestFile::parse("GET /a\nAccept: text/html\n");
    }

    public function testHarKeepsTheAppsHostOnly(): void
    {
        $har = json_encode(['log' => ['entries' => [
            ['request' => ['method' => 'post', 'url' => 'https://app.test/login', 'headers' => [['name' => 'Host', 'value' => 'app.test'], ['name' => 'Cookie', 'value' => 'a=1']], 'postData' => ['text' => 'u=1']]],
            ['request' => ['method' => 'GET', 'url' => 'https://cdn.other/x.js', 'headers' => []]],
            ['request' => ['method' => 'GET', 'url' => 'https://app.test/', 'headers' => []]],
        ]]]);
        $reqs = RequestFile::parse($har, true);
        self::assertSame(['POST /login', 'GET /'], array_map(static fn (Request $r) => $r->label(), $reqs));
        self::assertSame([['Cookie', 'a=1']], $reqs[0]->headers);
        self::assertSame('u=1', $reqs[0]->body);
    }

    public function testWireDropsHopByHopHeadersAndSetsLength(): void
    {
        $r = new Request('POST', '/x', [['Accept-Encoding', 'gzip'], ['X-A', 'b'], [':authority', 'h']], 'body');
        self::assertSame("POST /p/x HTTP/1.1\r\nX-A: b\r\nHost: h:1\r\nContent-Length: 4\r\nConnection: close\r\n\r\nbody", $r->wire('h:1', '/p'));
    }

    public function testParsesChunkedResponses(): void
    {
        $r = Response::parse("HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nContent-Type: text/plain\r\n\r\n5\r\nhello\r\n7;x=y\r\n, world\r\n0\r\n\r\n", 0.1);
        self::assertSame([200, 'hello, world', 'text/plain'], [$r->status, $r->body, $r->headers['content-type']]);
        self::assertSame('ab', Response::parse("HTTP/1.0 404 Not Found\r\nContent-Length: 2\r\n\r\nabXX", 0)->body);
    }

    public function testReplaysAgainstPhpBuiltinServerWithWorkers(): void
    {
        if (!function_exists('proc_open') || PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('needs proc_open and PHP_CLI_SERVER_WORKERS');
        }
        $root = sys_get_temp_dir() . '/fastphp-web-' . getmypid();
        @mkdir($root);
        file_put_contents("$root/index.php", '<?php header("X-Pid: " . getmypid()); echo $_SERVER["REQUEST_METHOD"], " ", $_SERVER["REQUEST_URI"], " ", file_get_contents("php://input"), " ", PHP_SAPI;');
        $server = new Server([PHP_BINARY, '-n'], $root, null, $root, "$root/server.log", [], 3);
        try {
            $reqs = [];
            for ($i = 0; $i < 12; $i++) {
                $reqs[] = new Request($i % 2 ? 'POST' : 'GET', "/item/$i?q=$i", [], $i % 2 ? "b$i" : '');
            }
            [$responses, $seconds] = $server->client()->replay($reqs, 3);
            self::assertCount(12, $responses);
            self::assertSame('POST /item/3?q=3 b3 cli-server', $responses[3]->body, 'responses come back in request order');
            self::assertSame('GET /item/4?q=4  cli-server', $responses[4]->body);
            self::assertGreaterThan(0, $seconds);
            $pids = array_unique(array_map(static fn (Response $r) => $r->headers['x-pid'], $responses));
            self::assertGreaterThanOrEqual(1, count($pids));
        } finally {
            $server->stop();
            array_map('unlink', glob("$root/*"));
            rmdir($root);
        }
        self::assertFalse(\FastPhp\Web\Client::listening('127.0.0.1', $server->port), 'stop() takes the workers down too');
    }
}
