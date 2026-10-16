<?php

declare(strict_types=1);

namespace LockSend\Support;

final class Migrator
{
    public function __construct(private readonly Db $db, private readonly string $dir)
    {
    }

    /** @return list<string> applied migration names */
    public function run(): array
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations'
            . ' (name VARCHAR(100) NOT NULL PRIMARY KEY, applied_at BIGINT NOT NULL)'
        );
        $done = array_column($this->db->all('SELECT name FROM schema_migrations'), 'name');
        $files = glob(rtrim($this->dir, '/') . '/*.sql') ?: [];
        sort($files);
        $applied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            $sql = (string) file_get_contents($file);
            foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
                $this->db->exec($stmt);
            }
            $this->db->exec('INSERT INTO schema_migrations (name, applied_at) VALUES (?, ?)', [$name, time()]);
            $applied[] = $name;
        }
        return $applied;
    }
}
