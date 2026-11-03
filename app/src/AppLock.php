<?php

declare(strict_types=1);

namespace App;

/**
 * Makes sure only one copy of the app runs (and updates) at a time.
 *
 * It's an *advisory* lock: the OS doesn't stop anyone from touching the file,
 * it only tells cooperating processes (other copies of this app) that someone
 * already holds it.
 *
 * The lock belongs to the open file handle, not to the process. That matters
 * for the restart: main.php releases it right before pcntl_exec(), otherwise
 * the restarted app could inherit the old handle and lock itself out.
 * If the process dies, the OS closes the handle and the lock goes away with it.
 */
final class AppLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private string $path)
    {
    }

    /** Returns false right away (no waiting) if another process holds the lock. */
    public function acquire(): bool
    {
        // 'c' = open for writing, create if missing, don't truncate.
        // @ hides PHP's warning: we report the failure by returning false.
        $handle = @fopen($this->path, 'c');
        if ($handle === false) {
            return false;
        }
        $this->handle = $handle;

        // LOCK_EX = exclusive, LOCK_NB = don't block, fail immediately.
        return flock($this->handle, LOCK_EX | LOCK_NB);
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
