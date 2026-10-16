<?php

declare(strict_types=1);

namespace LockSend\Tests;

use LockSend\RateLimit\RedisKv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * URL parsing is always tested. The behavioural tests run against a real redis-server when
 * one is available on PATH (started on a private unix socket and on a loopback TCP port),
 * through both the ext-redis path and the built-in socket client.
 */
final class RedisKvTest extends TestCase
{
    /** @var resource|null */
    private static $proc = null;
    private static string $dir = '';
    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        $bin = trim((string) shell_exec('command -v redis-server 2>/dev/null'));
        if ($bin === '') {
            return;
        }
        self::$dir = sys_get_temp_dir() . '/ls-redis-' . bin2hex(random_bytes(4));
        mkdir(self::$dir, 0700);
        self::$port = random_int(20000, 40000);
        self::$proc = proc_open(
            [$bin, '--port', (string) self::$port, '--bind', '127.0.0.1', '--unixsocket', self::$dir . '/r.sock',
             '--unixsocketperm', '700', '--save', '', '--appendonly', 'no', '--dir', self::$dir, '--loglevel', 'warning'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        for ($i = 0; $i < 50 && !file_exists(self::$dir . '/r.sock'); $i++) {
            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$proc !== null) {
            proc_terminate(self::$proc);
            proc_close(self::$proc);
            @unlink(self::$dir . '/r.sock');
            @rmdir(self::$dir);
        }
    }

    /** @return array<string,array{string,array<string,mixed>}> */
    public static function urls(): array
    {
        return [
            'unix three slashes' => ['unix:///run/redis/locksend.sock', ['scheme' => 'unix', 'path' => '/run/redis/locksend.sock']],
            'unix one slash' => ['unix:/run/redis.sock', ['scheme' => 'unix', 'path' => '/run/redis.sock']],
            'tcp default port' => ['tcp://10.0.0.5', ['scheme' => 'tcp', 'host' => '10.0.0.5', 'port' => 6379]],
            'tcp with port' => ['tcp://127.0.0.1:6399', ['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 6399]],
            'tcp with password' => ['tcp://:p%40ss@127.0.0.1:7000', ['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 7000, 'pass' => 'p@ss']],
        ];
    }

    #[DataProvider('urls')]
    public function testParseUrl(string $url, array $expected): void
    {
        $this->assertSame($expected, RedisKv::parseUrl($url));
    }

    public function testParseUrlRejectsGarbage(): void
    {
        foreach (['unix://relative.sock', 'http://x', '', 'unix:'] as $bad) {
            try {
                RedisKv::parseUrl($bad);
                $this->fail("accepted $bad");
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return array<string,array{string,bool}> */
    public static function transports(): array
    {
        $cases = [];
        foreach ([true, false] as $ext) {
            if ($ext && !class_exists(\Redis::class)) {
                continue;
            }
            $label = $ext ? 'ext-redis' : 'socket client';
            $cases["$label / unix"] = ['unix', $ext];
            $cases["$label / tcp"] = ['tcp', $ext];
        }
        return $cases;
    }

    #[DataProvider('transports')]
    public function testAgainstRealRedis(string $kind, bool $useExt): void
    {
        if (self::$proc === null) {
            $this->markTestSkipped('redis-server not available');
        }
        $url = $kind === 'unix' ? 'unix://' . self::$dir . '/r.sock' : 'tcp://127.0.0.1:' . self::$port;
        $kv = new RedisKv($url, 2.0, $useExt);
        $p = 'k' . bin2hex(random_bytes(3));

        $this->assertTrue($kv->ping());
        $this->assertNull($kv->get("$p:a"));
        $kv->set("$p:a", 'v', 30);
        $this->assertSame('v', $kv->get("$p:a"));
        $this->assertTrue($kv->setNx("$p:n", '1', 30));
        $this->assertFalse($kv->setNx("$p:n", '2', 30));
        $this->assertSame(1, $kv->incrWindow("$p:w", 30));
        $this->assertSame(2, $kv->incrWindow("$p:w", 30));
        $kv->setBit("$p:b", 3, 30);
        $kv->setBit("$p:b", 9, 30);
        $kv->setBit("$p:b", 9, 30);
        $this->assertSame(2, $kv->bitCount("$p:b"));
        $kv->expire("$p:a", 1);
        $kv->del("$p:n", "$p:w");
        $this->assertNull($kv->get("$p:n"));
        $this->assertSame(0, $kv->bitCount("$p:none"));
    }

    public function testUnreachableRedisThrowsRuntimeException(): void
    {
        $this->expectException(\RuntimeException::class);
        (new RedisKv('unix:///nonexistent/redis.sock', 0.5, false))->get('x');
    }
}
