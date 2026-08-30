<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

class LibraryCache
{
    /**
     * Per-request memo. Keyed identically to the cache store.
     *
     * @var array<string, mixed>
     */
    private array $memo = [];

    /**
     * Per-request copy of the key index, so track() reads it once rather than per call.
     *
     * @var array<int, string>|null
     */
    private ?array $tracked = null;

    /**
     * Resolve a value, hitting the filesystem at most once per request.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public function remember(string $key, Closure $callback): mixed
    {
        $key = $this->qualify($key);

        // Before track(), not after. Tracking reads the key index out of the cache
        // store, which is the database by default, so doing it on the way in would
        // spend a query on every memo hit - which is the one thing this class exists
        // to avoid.
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $this->track($key);

        $ttl = (int) config('minizo.library.cache_ttl', 300);

        // A non-positive TTL disables the cross-request layer entirely, which is
        // what the tests use so they observe real filesystem state.
        $value = $ttl > 0
            ? Cache::remember($key, $ttl, $callback)
            : $callback();

        return $this->memo[$key] = $value;
    }

    /** Drop one key from both layers. */
    public function forget(string $key): void
    {
        $key = $this->qualify($key);

        unset($this->memo[$key]);

        Cache::forget($key);
    }

    /** Drop everything. */
    public function flush(): void
    {
        foreach (array_keys($this->memo) as $key) {
            Cache::forget($key);
        }

        $this->memo = [];

        // Keys written by an earlier request are not in this request's memo, so
        // the index is what makes them reachable.
        foreach ($this->trackedKeys() as $key) {
            Cache::forget($key);
        }

        Cache::forget($this->indexKey());

        $this->tracked = null;
    }

    /** Number of filesystem scans avoided in this request. Test-facing. */
    public function memoizedKeys(): int
    {
        return count($this->memo);
    }

    /** The full cache key for one entry. */
    private function qualify(string $key): string
    {
        return 'minizo:library:'.$key;
    }

    /** Maintain an index of the keys we have written, so flush() can reach keys created by other requests without tag support. */
    private function track(string $key): void
    {
        $keys = $this->trackedKeys();

        if (in_array($key, $keys, true)) {
            return;
        }

        $keys[] = $key;

        $this->tracked = $keys;

        Cache::forever($this->indexKey(), $keys);
    }

    /**
     * @return array<int, string>
     */
    private function trackedKeys(): array
    {
        if ($this->tracked !== null) {
            return $this->tracked;
        }

        $keys = Cache::get($this->indexKey(), []);

        return $this->tracked = is_array($keys) ? $keys : [];
    }

    /** The key holding the list of keys this cache has written. */
    private function indexKey(): string
    {
        return 'minizo:library:keys';
    }
}
