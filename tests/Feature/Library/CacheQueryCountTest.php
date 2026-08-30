<?php

namespace Tests\Feature\Library;

use App\Support\LibraryCache;
use App\Support\Settings;
use App\Support\Sharing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The cache store is the database by default, so a cache read is a query.
 *
 * Both helpers here exist to avoid repeated work, and both used to read the store on
 * every call regardless. These pin the query counts so that cannot come back.
 */
class CacheQueryCountTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Queries issued while running the callback.
     */
    private function queriesDuring(callable $callback): int
    {
        $count = 0;

        DB::listen(function () use (&$count): void {
            $count++;
        });

        $callback();

        return $count;
    }

    public function test_settings_reads_the_store_once_however_many_times_it_is_asked(): void
    {
        Sharing::clearFake();

        // Prime, so the assertion measures steady state rather than the first miss.
        Settings::all();

        $queries = $this->queriesDuring(function (): void {
            for ($i = 0; $i < 50; $i++) {
                Settings::bool(Settings::SHARING_ENABLED, true);
            }
        });

        $this->assertSame(
            0,
            $queries,
            'Settings is memoised per request; 50 reads of a primed value should issue no queries.',
        );
    }

    public function test_writing_a_setting_drops_the_memo(): void
    {
        Sharing::clearFake();

        Settings::put(Settings::SHARING_ENABLED, true);
        $this->assertTrue(Sharing::enabled());

        Settings::put(Settings::SHARING_ENABLED, false);
        $this->assertFalse(Sharing::enabled(), 'put() must invalidate the memo, not just the cache entry.');
    }

    public function test_a_memoised_library_key_costs_no_query(): void
    {
        Storage::fake('music');

        $cache = app(LibraryCache::class);

        $cache->remember('folders', fn (): array => ['Spanish']);

        $queries = $this->queriesDuring(function () use ($cache): void {
            for ($i = 0; $i < 20; $i++) {
                $cache->remember('folders', fn (): array => ['Spanish']);
            }
        });

        $this->assertSame(
            0,
            $queries,
            'A memo hit must not read the key index. The memo exists to avoid exactly this.',
        );
    }

    public function test_flush_still_reaches_a_key_written_by_an_earlier_request(): void
    {
        Storage::fake('music');

        // Two instances, because the key index is what makes a key from one request
        // reachable from the next. A shared memo would hide a regression here.
        app(LibraryCache::class)->remember('files:Spanish', fn (): array => ['a.flac']);

        $second = new LibraryCache;
        $second->flush();

        $rebuilt = false;

        (new LibraryCache)->remember('files:Spanish', function () use (&$rebuilt): array {
            $rebuilt = true;

            return ['a.flac'];
        });

        $this->assertTrue($rebuilt, 'flush() must clear keys tracked by an earlier instance.');
    }
}
