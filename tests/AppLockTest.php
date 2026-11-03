<?php

declare(strict_types=1);

namespace Tests;

use App\AppLock;
use PHPUnit\Framework\TestCase;

final class AppLockTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/app-lock-test-' . uniqid() . '.lock';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function test_first_lock_succeeds(): void
    {
        $lock = new AppLock($this->path);

        $this->assertTrue($lock->acquire());
        $this->assertFileExists($this->path);
    }

    public function test_second_lock_on_same_file_fails(): void
    {
        $first = new AppLock($this->path);
        $second = new AppLock($this->path);

        $this->assertTrue($first->acquire());
        $this->assertFalse($second->acquire());
    }

    public function test_lock_can_be_taken_after_release(): void
    {
        $first = new AppLock($this->path);
        $second = new AppLock($this->path);

        $first->acquire();
        $first->release();

        $this->assertTrue($second->acquire());
    }
}
