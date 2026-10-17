<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use Illuminate\Contracts\Cache\Store;
use RuntimeException;

/**
 * A cache store whose backend is down (Redis unreachable, ...): every
 * operation throws.
 */
class UnavailableCacheStore implements Store
{
    private function down(): never
    {
        throw new RuntimeException('Connection refused [tcp://127.0.0.1:6379]');
    }

    public function get($key)
    {
        $this->down();
    }

    public function many(array $keys)
    {
        $this->down();
    }

    public function put($key, $value, $seconds)
    {
        $this->down();
    }

    public function putMany(array $values, $seconds)
    {
        $this->down();
    }

    public function increment($key, $value = 1)
    {
        $this->down();
    }

    public function decrement($key, $value = 1)
    {
        $this->down();
    }

    public function forever($key, $value)
    {
        $this->down();
    }

    public function forget($key)
    {
        $this->down();
    }

    public function flush()
    {
        $this->down();
    }

    public function getPrefix()
    {
        return '';
    }
}
