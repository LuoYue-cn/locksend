<?php

declare(strict_types=1);

namespace LockSend\Storage;

use LockSend\Support\Config;
use LockSend\Support\HttpException;

/**
 * Ciphertext chunks as individual files: <data>/blobs/<share_id>/<index>.
 * Writes go to tmp/ first and are renamed into place, so a chunk is either
 * absent or complete, and re-uploading an index is idempotent.
 */
final class BlobStore
{
    public function __construct(private readonly Config $config)
    {
    }

    private function dir(string $id): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{22}$/D', $id) !== 1) {
            throw new \InvalidArgumentException('bad share id');
        }
        return $this->config->blobsDir() . '/' . $id;
    }

    public function path(string $id, int $index): string
    {
        return $this->dir($id) . '/' . $index;
    }

    /**
     * Streams the body into a temp file, refusing anything above $max bytes.
     *
     * @param resource $in
     * @return array{0:string,1:int} temp path and byte count
     */
    public function receive($in, int $max): array
    {
        $this->config->ensureDirs();
        $tmp = $this->config->tmpDir() . '/' . bin2hex(random_bytes(12));
        $out = fopen($tmp, 'xb');
        if ($out === false) {
            throw new \RuntimeException('cannot create temp file');
        }
        chmod($tmp, 0640);
        $total = 0;
        try {
            while (!feof($in)) {
                $buf = fread($in, 262144);
                if ($buf === false) {
                    throw new HttpException(400, 'read_failed');
                }
                if ($buf === '') {
                    continue;
                }
                $total += strlen($buf);
                if ($total > $max) {
                    throw new HttpException(413, 'too_large');
                }
                if (fwrite($out, $buf) !== strlen($buf)) {
                    throw new HttpException(507, 'storage_full');
                }
            }
            fflush($out);
            if (function_exists('fsync')) {
                fsync($out);
            }
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($tmp);
            throw $e;
        }
        fclose($out);
        return [$tmp, $total];
    }

    public function commit(string $tmp, string $id, int $index): void
    {
        $dir = $this->dir($id);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            @unlink($tmp);
            throw new \RuntimeException('cannot create blob dir');
        }
        if (!rename($tmp, $dir . '/' . $index)) {
            @unlink($tmp);
            throw new \RuntimeException('cannot store chunk');
        }
    }

    public function discard(string $tmp): void
    {
        @unlink($tmp);
    }

    public function size(string $id, int $index): ?int
    {
        $p = $this->path($id, $index);
        clearstatcache(true, $p);
        $s = is_file($p) ? filesize($p) : false;
        return $s === false ? null : $s;
    }

    /** @return array<int,int> index => byte size */
    public function sizes(string $id): array
    {
        $dir = $this->dir($id);
        $out = [];
        $h = is_dir($dir) ? opendir($dir) : false;
        if ($h === false) {
            return [];
        }
        while (($e = readdir($h)) !== false) {
            if (ctype_digit($e)) {
                $s = filesize($dir . '/' . $e);
                if ($s !== false) {
                    $out[(int) $e] = $s;
                }
            }
        }
        closedir($h);
        ksort($out);
        return $out;
    }

    public function deleteAll(string $id): void
    {
        $dir = $this->dir($id);
        if (!is_dir($dir)) {
            return;
        }
        $h = opendir($dir);
        if ($h !== false) {
            while (($e = readdir($h)) !== false) {
                if ($e !== '.' && $e !== '..') {
                    @unlink($dir . '/' . $e);
                }
            }
            closedir($h);
        }
        @rmdir($dir);
    }

    /** @return list<string> share ids that have a blob directory */
    public function listIds(): array
    {
        $root = $this->config->blobsDir();
        $ids = [];
        $h = is_dir($root) ? opendir($root) : false;
        if ($h === false) {
            return [];
        }
        while (($e = readdir($h)) !== false) {
            if (preg_match('/^[A-Za-z0-9_-]{22}$/D', $e) === 1) {
                $ids[] = $e;
            }
        }
        closedir($h);
        return $ids;
    }

    public function freeBytes(): int
    {
        $this->config->ensureDirs();
        $f = @disk_free_space($this->config->dataDir);
        return $f === false ? PHP_INT_MAX : (int) $f;
    }
}
