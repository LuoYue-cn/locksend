<?php

declare(strict_types=1);

namespace LockSend\Tests;

use LockSend\Support\Base64Url;
use PHPUnit\Framework\TestCase;

/** Real parallel PHP processes racing on one share in one SQLite database. */
final class ConcurrencyTest extends TestCase
{
    /**
     * @param list<array{0:int,1:string}> $workers attempts and mode ("wrong"|"right") per process
     * @return array<string,int> outcome => count
     */
    private function race(Harness $h, string $id, array $workers): array
    {
        $start = microtime(true) + 1.0;
        $procs = [];
        foreach ($workers as [$attempts, $mode]) {
            $cmd = [PHP_BINARY, __DIR__ . '/support/guess.php', $h->dir, $id, (string) $attempts, (string) $start, $mode];
            $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $h->dbEnv + getenv());
            $this->assertIsResource($p);
            $procs[] = [$p, $pipes];
        }
        $counts = [];
        foreach ($procs as [$p, $pipes]) {
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($p), $err);
            $this->assertSame('', $err);
            foreach (array_filter(explode("\n", $out)) as $line) {
                $counts[$line] = ($counts[$line] ?? 0) + 1;
            }
        }
        return $counts;
    }

    public function testParallelWrongGuessesCannotExceedMaxFails(): void
    {
        $h = new Harness();
        $s = $h->uploadShare(1, ['max_fails' => 5]);
        $counts = $this->race($h, $s['id'], array_fill(0, 8, [12, 'wrong']));

        $this->assertSame(96, array_sum($counts));
        $this->assertSame(4, $counts['bad_code'] ?? 0, 'exactly max_fails - 1 guesses may be answered as "wrong"');
        $this->assertSame(1, $counts['destroyed'] ?? 0, 'exactly one guess performs the destruction');
        $this->assertSame(91, $counts['gone'] ?? 0, 'every other guess finds the share already gone');
        $this->assertSame('destroyed', $h->app->shares()->find($s['id'])['status']);
        $this->assertDirectoryDoesNotExist($h->config->blobsDir() . '/' . $s['id']);
    }

    public function testParallelWrongGuessesWithFreezeLockExactlyOnce(): void
    {
        $h = new Harness();
        $s = $h->uploadShare(1, ['max_fails' => 3, 'fail_action' => 'freeze_24h']);
        $counts = $this->race($h, $s['id'], array_fill(0, 6, [10, 'wrong']));

        $this->assertSame(60, array_sum($counts));
        $this->assertSame(2, $counts['bad_code'] ?? 0);
        $this->assertSame(58, $counts['frozen'] ?? 0, 'the 3rd failure freezes; all later attempts are locked out');
        $row = $h->app->shares()->find($s['id']);
        $this->assertSame('ready', $row['status']);
        $this->assertGreaterThan(0, (int) $row['frozen_until']);
    }

    public function testCorrectCodeRacingWithGuessersNeverBypassesTheLimit(): void
    {
        $h = new Harness();
        $s = $h->uploadShare(1, ['max_fails' => 3]);
        file_put_contents($h->dir . '/right-auth', Base64Url::encode($s['auth']));
        $workers = array_merge(array_fill(0, 5, [10, 'wrong']), [[10, 'right']]);
        $counts = $this->race($h, $s['id'], $workers);

        $this->assertSame(60, array_sum($counts));
        // A correct code resets the consecutive-failure counter, so wrong answers can only exceed
        // max_fails - 1 when a success landed in between; with no success the limit is exact.
        $successes = $counts['ok'] ?? 0;
        $this->assertLessThanOrEqual(2 * (1 + $successes), $counts['bad_code'] ?? 0);
        if ($successes === 0) {
            $this->assertLessThanOrEqual(2, $counts['bad_code'] ?? 0, 'never more than max_fails - 1 wrong answers');
        }
        $this->assertLessThanOrEqual(1, $counts['destroyed'] ?? 0);
        $this->assertSame(0, (int) $h->app->db()->one('SELECT COUNT(*) AS n FROM shares WHERE fail_count > 2')['n']);
        // Either the owner got in before destruction, or the share was destroyed; never both a leak and a miscount.
        $this->assertTrue(isset($counts['ok']) || ($counts['destroyed'] ?? 0) === 1);
    }
}
