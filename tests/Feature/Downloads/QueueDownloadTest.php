<?php

namespace Tests\Feature\Downloads;

use App\Enums\AudioFormat;
use App\Enums\DownloadStatus;
use App\Enums\Permission;
use App\Exceptions\DownloadException;
use App\Jobs\DownloadTrackJob;
use App\Models\DownloadJob;
use App\Models\User;
use App\Services\Download\DownloadQueue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Enqueueing: the point at which "where does this file land" is decided. */
class QueueDownloadTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://music.youtube.com/watch?v=abc12345678';

    private function library(array $folders = ['Spanish', 'Folk']): DownloadQueue
    {
        $disk = Storage::fake('music');

        foreach ($folders as $folder) {
            $disk->makeDirectory($folder);
        }

        Queue::fake();

        return app(DownloadQueue::class);
    }

    public function test_it_creates_a_queued_row_and_dispatches_the_job(): void
    {
        $queue = $this->library();
        $user = User::factory()->create();

        $job = $queue->push($user, self::URL, 'Spanish');

        $this->assertSame(DownloadStatus::Queued, $job->status);
        $this->assertSame('Spanish', $job->folder);
        $this->assertSame(AudioFormat::Flac, $job->format);
        $this->assertSame($user->id, $job->user_id);
        $this->assertSame(0, $job->progress_percent);

        Queue::assertPushed(DownloadTrackJob::class, fn (DownloadTrackJob $pushed): bool => $pushed->record->is($job));
    }

    public function test_the_job_goes_on_the_dedicated_downloads_queue(): void
    {
        /*
         * Not cosmetic. A download runs for tens of seconds to minutes; with two
         * workers, two downloads on the default queue would starve mail and every
         * other job for as long as they run.
         */
        $queue = $this->library();

        $queue->push(User::factory()->create(), self::URL, 'Spanish');

        Queue::assertPushedOn(config('minizo.downloads.queue'), DownloadTrackJob::class);
    }

    public function test_a_user_without_the_downloader_permission_cannot_queue(): void
    {
        $queue = $this->library();

        $this->expectException(AuthorizationException::class);

        $queue->push(
            User::factory()->without([Permission::Downloader])->create(),
            self::URL,
            'Spanish',
        );
    }

    public function test_a_user_cannot_queue_into_a_folder_they_cannot_see(): void
    {
        $queue = $this->library();
        $user = User::factory()->withFolders(['Spanish'])->create();

        $this->expectException(AuthorizationException::class);

        $queue->push($user, self::URL, 'Folk');
    }

    public function test_an_unknown_destination_is_reported_not_created(): void
    {
        // Otherwise a typo in the payload would quietly add a folder to the library
        // that nobody has access to.
        $queue = $this->library();

        try {
            $queue->push(User::factory()->create(), self::URL, 'NoSuchFolder');
            $this->fail('The push should have been refused.');
        } catch (DownloadException $e) {
            $this->assertSame('folder', $e->field);
        }

        Storage::disk('music')->assertMissing('NoSuchFolder');
        $this->assertSame(0, DownloadJob::count());
    }

    public function test_a_folder_lock_overrides_the_submitted_destination(): void
    {
        /*
         * The design's downloader restriction: "new downloads from this user are
         * forced into the locked folder". A default the user could override would be
         * useless - the point is that the track lands somewhere for review.
         */
        $queue = $this->library(['Spanish', 'Unprocessed']);

        $user = User::factory()->lockedDownloader('Unprocessed')->create();

        $job = $queue->push($user, self::URL, 'Spanish');

        $this->assertSame('Unprocessed', $job->folder);
    }

    public function test_a_lock_pointing_at_a_deleted_folder_is_reported(): void
    {
        // An administrative problem, so it says so rather than silently recreating
        // the folder or falling back to somewhere the user chose.
        $queue = $this->library(['Spanish']);

        $user = User::factory()->lockedDownloader('Unprocessed')->create();

        try {
            $queue->push($user, self::URL, 'Spanish');
            $this->fail('The push should have been refused.');
        } catch (DownloadException $e) {
            $this->assertStringContainsString('Unprocessed', $e->getMessage());
            $this->assertSame('folder', $e->field);
        }

        Storage::disk('music')->assertMissing('Unprocessed');
    }

    public function test_a_format_lock_overrides_the_requested_format(): void
    {
        $queue = $this->library();

        $user = User::factory()->lockedDownloader('Spanish', AudioFormat::Flac)->create();

        $this->assertSame(
            AudioFormat::Flac,
            $queue->resolveFormat($user, null),
        );
    }

    #[DataProvider('rejectedUrls')]
    public function test_it_refuses_something_that_is_not_a_url(string $url): void
    {
        $queue = $this->library();

        try {
            $queue->push(User::factory()->create(), $url, 'Spanish');
            $this->fail("[{$url}] should have been refused.");
        } catch (DownloadException $e) {
            $this->assertSame('url', $e->field);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedUrls(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
            'no scheme' => ['music.youtube.com/watch?v=abc'],
            'not http' => ['file:///etc/passwd'],
            'javascript' => ['javascript:alert(1)'],
            'scheme only' => ['https://'],
        ];
    }

    // ------------------------------------------------------------- egress control

    /**
     * yt-dlp is a full HTTP client running inside the container, so an unrestricted URL
     * makes "may download tracks" mean "may make the server request anything it can
     * reach" - cloud metadata, the database, a service on the Docker network. The failure
     * text comes back to the user, which would make it a scanner rather than a blind hole.
     */
    #[DataProvider('privateUrls')]
    public function test_it_refuses_a_url_pointing_inside_the_network(string $url): void
    {
        $queue = $this->library();

        try {
            $queue->push(User::factory()->create(), $url, 'Spanish');
            $this->fail("[{$url}] should have been refused.");
        } catch (DownloadException $e) {
            $this->assertSame('url', $e->field);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function privateUrls(): array
    {
        return [
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'loopback' => ['http://127.0.0.1:9200/_cat/indices'],
            'loopback by name' => ['http://localhost:6379/'],
            'ipv6 loopback' => ['http://[::1]:8080/'],
            'ipv4-mapped ipv6' => ['http://[::ffff:127.0.0.1]/'],
            'rfc1918 ten' => ['http://10.0.0.5:8080/'],
            'rfc1918 192' => ['http://192.168.1.1/'],
            'rfc1918 172' => ['http://172.16.0.1/'],
            'link local' => ['http://169.254.1.1/'],
            'unresolvable' => ['https://this-host-does-not-exist.minizo.invalid/x'],
        ];
    }

    public function test_the_guard_can_be_turned_off_for_a_deliberate_internal_host(): void
    {
        // Someone self-hosting a media server on their own LAN has a real use for this.
        // It is opt-in, so the default stays closed.
        config(['minizo.downloads.block_private_hosts' => false]);

        $queue = $this->library();

        $job = $queue->push(User::factory()->create(), 'http://192.168.1.50/track.flac', 'Spanish');

        $this->assertSame('http://192.168.1.50/track.flac', $job->url);
    }

    public function test_a_public_url_still_queues(): void
    {
        $queue = $this->library();

        $job = $queue->push(User::factory()->create(), self::URL, 'Spanish');

        $this->assertSame(self::URL, $job->url);
    }

    public function test_it_accepts_any_http_url_not_only_youtube(): void
    {
        // yt-dlp supports over a thousand sites; validating against a host list would
        // reject things that work. The design's placeholder shows the intended use,
        // not the only one.
        $queue = $this->library();

        $job = $queue->push(User::factory()->create(), 'https://soundcloud.com/artist/track', 'Spanish');

        $this->assertSame('https://soundcloud.com/artist/track', $job->url);
    }

    public function test_destinations_are_limited_to_folders_the_user_can_see(): void
    {
        $queue = $this->library(['Spanish', 'Folk', 'Hidden']);

        $user = User::factory()->withFolders(['Folk', 'Spanish'])->create();

        $this->assertSame(['Folk', 'Spanish'], $queue->destinationsFor($user));
    }

    public function test_a_locked_user_is_offered_exactly_one_destination(): void
    {
        // Which is also what makes the screen render a static badge instead of a
        // select the user could change.
        $queue = $this->library(['Spanish', 'Unprocessed']);

        $user = User::factory()->lockedDownloader('Unprocessed')->create();

        $this->assertSame(['Unprocessed'], $queue->destinationsFor($user));
    }
}
