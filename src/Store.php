<?php
declare(strict_types=1);

namespace Codivanta;

/** Transactional single-node JSON store for synthetic demonstration data ONLY.
 * Locking + atomic rename prevents competing PHP requests from losing updates.
 * Never use this adapter for real PHI or production multi-node deployments.
 */
final class Store
{
    public function __construct(private string $path)
    {
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        if (!is_file($path)) $this->transaction(static fn(array &$s) => null);
    }

    public function read(): array
    {
        $handle = fopen($this->path . '.lock', 'c');
        if (!$handle || !flock($handle, LOCK_SH)) throw new \RuntimeException('Storage lock failed');
        try { return $this->load(); }
        finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    public function transaction(callable $fn): mixed
    {
        $handle = fopen($this->path . '.lock', 'c');
        if (!$handle || !flock($handle, LOCK_EX)) throw new \RuntimeException('Storage lock failed');
        try {
            $state = $this->load();
            $result = $fn($state);
            $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $temp = tempnam(dirname($this->path), '.codivanta-');
            if ($temp === false) throw new \RuntimeException('Temp file creation failed');
            chmod($temp, 0600);
            if (file_put_contents($temp, $encoded) === false || !rename($temp, $this->path)) {
                @unlink($temp);
                throw new \RuntimeException('Failed to persist transaction');
            }
            return $result;
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    private function load(): array
    {
        if (!is_file($this->path)) return self::empty();
        $contents = file_get_contents($this->path);
        if ($contents === false) throw new \RuntimeException('Cannot read store');
        $state = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($state)) throw new \RuntimeException('Store corrupted');
        return $state;
    }

    public static function empty(): array
    {
        return ['patients'=>[], 'encounters'=>[], 'claims'=>[], 'audit'=>[], 'codes'=>[],
            'catalog'=>['version'=>'FY2027', 'status'=>'not_imported', 'count'=>0, 'sha256'=>null, 'source'=>null, 'imported_at'=>null],
            'meta'=>['created_at'=>gmdate(DATE_ATOM), 'schema'=>1]];
    }

    public static function id(string $prefix): string { return $prefix . '_' . bin2hex(random_bytes(6)); }

    public static function event(array &$state, string $actor, string $action, string $object, array $details = []): void
    {
        $previous = count($state['audit']) ? end($state['audit'])['hash'] : str_repeat('0',64);
        $record = ['id'=>self::id('evt'), 'at'=>gmdate(DATE_ATOM), 'actor'=>$actor,
            'action'=>$action, 'object'=>$object, 'details'=>$details, 'previous_hash'=>$previous];
        $record['hash'] = hash('sha256', $previous . json_encode($record, JSON_THROW_ON_ERROR));
        $state['audit'][] = $record;
    }

    public static function verify(array $state): bool
    {
        $previous = str_repeat('0', 64);
        foreach ($state['audit'] as $record) {
            if (($record['previous_hash'] ?? '') !== $previous) return false;
            $hash = $record['hash'] ?? '';
            unset($record['hash']);
            if (!hash_equals($hash, hash('sha256', $previous . json_encode($record, JSON_THROW_ON_ERROR)))) return false;
            $previous = $hash;
        }
        return true;
    }
}
