<?php

declare(strict_types=1);

namespace Aegis\Captcha\Storage;

use RuntimeException;

/** Local-filesystem replay store. Use only on a shared, lock-capable filesystem. */
final class FileNonceStorage implements NonceStorageInterface
{
    private string $storageDir;
    private string $lockPath;

    public function __construct(?string $storageDir = null)
    {
        $this->storageDir = $storageDir ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aegis_nonces');
        if (is_link($this->storageDir)) {
            throw new RuntimeException('Aegis nonce directory must not be a symbolic link.');
        }
        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0700, true) && !is_dir($this->storageDir)) {
            throw new RuntimeException('Failed to initialize Aegis nonce directory.');
        }
        if (DIRECTORY_SEPARATOR !== '\\') {
            if (!chmod($this->storageDir, 0700) || !is_writable($this->storageDir)) {
                throw new RuntimeException('Aegis nonce directory must be writable and private.');
            }
        } else {
            $probe = $this->storageDir . DIRECTORY_SEPARATOR . '.probe_' . bin2hex(random_bytes(4));
            $probeFp = @fopen($probe, 'w');
            if ($probeFp === false) {
                throw new RuntimeException('Aegis nonce directory must be writable and private.');
            }
            fclose($probeFp);
            @unlink($probe);
        }
        $this->lockPath = $this->storageDir . DIRECTORY_SEPARATOR . '.aegis.lock';
        if (is_link($this->lockPath)) {
            throw new RuntimeException('Aegis lock file must not be a symbolic link.');
        }
        if (random_int(1, 100) === 1) {
            $this->garbageCollect();
        }
    }

    public function claimNonce(string $nonce, int $ttlSeconds): bool
    {
        if ($nonce === '' || $ttlSeconds < 1) {
            return false;
        }
        $lock = $this->acquireLock();
        try {
            $hash = hash('sha256', $nonce);
            $subDir = $this->storageDir . DIRECTORY_SEPARATOR . substr($hash, 0, 2);
            if (!is_dir($subDir) && !mkdir($subDir, 0700, true) && !is_dir($subDir)) {
                return false;
            }
            if (is_link($subDir) || (DIRECTORY_SEPARATOR !== '\\' && !chmod($subDir, 0700))) {
                return false;
            }
            $path = $subDir . DIRECTORY_SEPARATOR . $hash . '.nonce';
            $fp = @fopen($path, 'c+');
            if (!$fp) {
                return false;
            }
            try {
                rewind($fp);
                $expiry = (int)stream_get_contents($fp);
                if ($expiry > time()) {
                    return false;
                }
                ftruncate($fp, 0);
                rewind($fp);
                $value = (string)(time() + $ttlSeconds);
                $written = fwrite($fp, $value);
                fflush($fp);
                return $written === strlen($value);
            } finally {
                fclose($fp);
            }
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function hasNonce(string $nonce): bool
    {
        $hash = hash('sha256', $nonce);
        $path = $this->storageDir . DIRECTORY_SEPARATOR . substr($hash, 0, 2) . DIRECTORY_SEPARATOR . $hash . '.nonce';
        if (is_link($path) || !is_file($path)) {
            return false;
        }
        return (int)@file_get_contents($path) > time();
    }

    /** Garbage collection shares the global lock so it cannot unlink a file being claimed. */
    public function garbageCollect(): void
    {
        $lock = $this->acquireLock();
        try {
            $now = time();
            $subDirs = glob($this->storageDir . DIRECTORY_SEPARATOR . '[0-9a-f][0-9a-f]', GLOB_ONLYDIR) ?: [];
            foreach ($subDirs as $dir) {
                if (is_link($dir)) {
                    continue;
                }
                foreach (glob($dir . DIRECTORY_SEPARATOR . '*.nonce') ?: [] as $file) {
                    if (is_link($file)) {
                        continue;
                    }
                    if ((int)@file_get_contents($file) <= $now) {
                        @unlink($file);
                    }
                }
                if (count(glob($dir . DIRECTORY_SEPARATOR . '*.nonce') ?: []) === 0) {
                    @rmdir($dir);
                }
            }
        } finally {
            $this->releaseLock($lock);
        }
    }

    /** @return resource */
    private function acquireLock()
    {
        $fp = @fopen($this->lockPath, 'c');
        if (!$fp || !flock($fp, LOCK_EX)) {
            if (is_resource($fp)) fclose($fp);
            throw new RuntimeException('Unable to acquire Aegis nonce-storage lock.');
        }
        return $fp;
    }

    /** @param resource $fp */
    private function releaseLock($fp): void
    {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
