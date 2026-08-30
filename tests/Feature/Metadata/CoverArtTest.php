<?php

namespace Tests\Feature\Metadata;

use App\Models\Share;
use App\Models\User;
use App\Services\Metadata\AudioTagReader;
use App\Services\Metadata\FlacTagWriter;
use App\Services\Metadata\MetadataWriter;
use App\Services\Metadata\Metaflac;
use App\Support\LibraryFile;
use App\Support\LibraryFolder;
use App\Support\TrackMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Reading tags and artwork off real files, and serving that artwork. */
class CoverArtTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        // A real directory, not Storage::fake's in-memory adapter: getID3 and metaflac both
        // need a path they can open.
        $this->root = storage_path('framework/testing/covers-'.getmypid());

        if (! is_dir($this->root.'/Spanish')) {
            mkdir($this->root.'/Spanish', 0o777, true);
        }

        config()->set('filesystems.disks.music.root', $this->root);
        Storage::forgetDisk('music');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->root.'/*') ?: [] as $entry) {
            is_dir($entry) ? @rmdir($entry) : @unlink($entry);
        }
        @rmdir($this->root);

        parent::tearDown();
    }

    private function binary(string $name): string
    {
        $path = (new ExecutableFinder)->find($name);

        if ($path === null) {
            $this->markTestSkipped("{$name} is needed for this test.");
        }

        return $path;
    }

    private function flac(string $filename = 'track.flac'): LibraryFile
    {
        $path = $this->root.'/Spanish/'.$filename;

        $process = new Process([
            $this->binary('ffmpeg'), '-y', '-loglevel', 'error', '-f', 'lavfi',
            '-i', 'anullsrc=r=48000:cl=stereo', '-t', '2',
            '-sample_fmt', 's32', '-c:a', 'flac', $path,
        ], timeout: 60);

        $process->run();

        if (! $process->isSuccessful() || ! file_exists($path)) {
            $this->markTestSkipped('ffmpeg could not produce a FLAC: '.$process->getErrorOutput());
        }

        return new LibraryFile(new LibraryFolder('Spanish'), $filename);
    }

    private function jpegPath(): string
    {
        $path = $this->root.'/cover.jpg';

        $process = new Process([
            $this->binary('ffmpeg'), '-y', '-loglevel', 'error', '-f', 'lavfi',
            '-i', 'color=c=blue:s=300x300', '-frames:v', '1', $path,
        ], timeout: 60);

        $process->run();

        if (! $process->isSuccessful()) {
            $this->markTestSkipped('ffmpeg could not produce a JPEG.');
        }

        return $path;
    }

    private function embedCover(LibraryFile $file): void
    {
        app(Metaflac::class)->run([
            '--import-picture-from='.$this->jpegPath(),
            $this->root.'/'.$file->path(),
        ]);
    }

    private function reader(): AudioTagReader
    {
        return app(AudioTagReader::class);
    }

    private function tag(LibraryFile $file, array $overrides = []): void
    {
        app(FlacTagWriter::class)->write($this->root.'/'.$file->path(), TrackMetadata::fromArray([
            'title' => 'Você já sabe',
            'artist' => 'Anitta & Los Brasileros',
            'album' => 'EQUILIBRIVM II',
            'year' => '2026',
            'genre' => 'pop',
            'trackNumber' => '1',
            'totalTracks' => 17,
            'isrc' => 'USUG12607502',
            'label' => 'Republic Records',
            'language' => 'por',
            'recordingId' => 'e367ed04-41d7-4780-8bce-b1fabb4e9c08',
            'releaseId' => '764016c0-b917-48c1-8fc0-f38c0d0f4c5b',
            ...$overrides,
        ]));
    }

    // ---------------------------------------------------------------- the reader

    public function test_it_reads_back_the_tags_minizo_wrote(): void
    {
        // Symmetry with FlacTagWriter matters: the writer uses uppercase Vorbis keys and
        // Picard's MUSICBRAINZ_* names, and a reader that missed them would make every
        // tagged file look untagged.
        $file = $this->flac();
        $this->tag($file);

        $tags = $this->reader()->read($file);

        $this->assertNotNull($tags);
        $this->assertSame('Você já sabe', $tags->title);
        $this->assertSame('Anitta & Los Brasileros', $tags->artist);
        $this->assertSame('EQUILIBRIVM II', $tags->album);
        $this->assertSame('2026', $tags->year);
        $this->assertSame('USUG12607502', $tags->isrc);
        $this->assertSame('Republic Records', $tags->label);
        $this->assertSame('e367ed04-41d7-4780-8bce-b1fabb4e9c08', $tags->musicbrainzTrackId);
        $this->assertTrue($tags->hasMusicBrainzIds());
    }

    public function test_it_reads_the_language_field_php_audio_does_not_map(): void
    {
        /*
         * Found by previewing a real track: php-audio's getLanguage() returned null for a
         * file that plainly carried LANGUAGE=por, because it does not map that Vorbis field
         * to its dedicated getter. The reader falls back to the raw comment.
         */
        $file = $this->flac();
        $this->tag($file);

        $this->assertSame('por', $this->reader()->read($file)->language);
    }

    public function test_it_reads_the_stream_facts_no_tagger_controls(): void
    {
        /*
         * The reason this is worth its own reader rather than re-reading what we wrote:
         * sample rate, bit depth and channel count come from the stream, and they are what
         * distinguishes a 24-bit master from a 16-bit rip.
         */
        $tags = $this->reader()->read($this->flac());

        $this->assertNotNull($tags);
        $this->assertSame(48000, $tags->sampleRate);
        $this->assertSame(2, $tags->channels);
        $this->assertSame(24, $tags->bitsPerSample);
        $this->assertTrue($tags->lossless);
        $this->assertSame('48 kHz · 24-bit · Stereo', $tags->streamLabel());
        $this->assertSame('0:02', $tags->durationLabel());
    }

    public function test_a_file_with_no_tags_is_reported_as_empty_not_as_a_failure(): void
    {
        // A fresh download before tagging. The preview says so rather than rendering
        // fifteen blank rows.
        $tags = $this->reader()->read($this->flac());

        $this->assertNotNull($tags);
        $this->assertTrue($tags->isEmpty());
        $this->assertFalse($tags->hasMusicBrainzIds());
    }

    public function test_an_unreadable_file_returns_null_rather_than_throwing(): void
    {
        // Truncated, still downloading, or not really the format its extension claims. A
        // preview that 500s is worse than one that says it could not read the file.
        Storage::disk('music')->put('Spanish/broken.flac', 'this is not a flac');

        $this->assertNull($this->reader()->read(new LibraryFile(new LibraryFolder('Spanish'), 'broken.flac')));
        $this->assertNull($this->reader()->cover(new LibraryFile(new LibraryFolder('Spanish'), 'broken.flac')));
    }

    public function test_a_missing_file_has_no_fingerprint(): void
    {
        $this->assertNull($this->reader()->fingerprint(new LibraryFile(new LibraryFolder('Spanish'), 'ghost.flac')));
    }

    public function test_it_finds_an_embedded_cover_and_its_dimensions(): void
    {
        $file = $this->flac();
        $this->embedCover($file);

        $tags = $this->reader()->read($file);

        $this->assertTrue($tags->hasCover);
        $this->assertSame('image/jpeg', $tags->coverMimeType);
        $this->assertSame(300, $tags->coverWidth);
        $this->assertSame(300, $tags->coverHeight);
        $this->assertNotNull($tags->coverLabel());
    }

    public function test_a_file_with_no_artwork_says_so(): void
    {
        $tags = $this->reader()->read($this->flac());

        $this->assertFalse($tags->hasCover);
        $this->assertNull($tags->coverLabel());
        $this->assertNull($this->reader()->cover($this->flac()));
    }

    public function test_the_fingerprint_changes_when_the_bytes_do(): void
    {
        // The backstop half of invalidation: a file replaced directly on the host, which no
        // amount of in-app bookkeeping can know about.
        $file = $this->flac();

        $before = $this->reader()->fingerprint($file);

        Storage::disk('music')->put($file->path(), 'completely different content');

        $this->assertNotSame($before, $this->reader()->fingerprint($file));
    }

    public function test_embedding_artwork_leaves_the_byte_size_untouched(): void
    {
        // Embedding a 300x300 JPEG into a FLAC does not change the file's byte size at all:
        // metaflac writes into the existing PADDING block rather than growing the file. With
        // mtime's one-second granularity, that makes a (mtime, size) key a weak backstop, which
        // is why MetadataWriter invalidates explicitly.
        //
        // Asserted on size rather than the fingerprint, which also depends on mtime and would
        // flake across a second boundary.
        $file = $this->flac();

        $disk = Storage::disk('music');
        $before = $disk->size($file->path());

        $this->embedCover($file);

        clearstatcache(true, $this->root.'/'.$file->path());

        $this->assertSame(
            $before,
            $disk->size($file->path()),
            'if this ever fails, FLAC padding behaviour changed and the note above is stale',
        );
    }

    public function test_forget_changes_the_fingerprint_even_when_mtime_and_size_cannot(): void
    {
        // mtime has one-second granularity and FLAC padding often absorbs a tag change without
        // moving the byte count, so two writes inside one second would produce the same
        // fingerprint. forget() bumps a revision counter that is part of the identity.
        $file = $this->flac();

        $before = $this->reader()->fingerprint($file);

        $this->reader()->forget($file);

        $this->assertNotSame($before, $this->reader()->fingerprint($file));
    }

    public function test_writing_tags_makes_the_preview_show_them_immediately(): void
    {
        // End to end through MetadataWriter, which is the path the modal takes - and the one
        // that has to invalidate the cache for the preview to be worth anything.
        $file = $this->flac();

        $this->assertTrue($this->reader()->read($file)->isEmpty());

        $this->actingAs(User::factory()->admin()->create());

        app(MetadataWriter::class)->write($file, TrackMetadata::fromArray([
            'title' => 'Você já sabe',
            'artist' => 'Anitta & Los Brasileros',
        ]));

        $this->assertSame('Você já sabe', $this->reader()->read($file)->title);
    }

    // -------------------------------------------------------- the cover endpoint

    public function test_the_endpoint_serves_the_embedded_artwork(): void
    {
        $file = $this->flac();
        $this->embedCover($file);

        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('files.cover', ['Spanish', $file->filename]));

        $response->assertOk()->assertHeader('content-type', 'image/jpeg');

        // Real bytes, not a placeholder.
        $this->assertSame("\xFF\xD8\xFF", substr($response->getContent(), 0, 3));
    }

    public function test_a_file_without_artwork_is_a_404_not_an_error(): void
    {
        /*
         * The ordinary case for an untagged download, and the reason the listing needs no
         * per-row disk read: the <img> simply fails and the generated tile stays visible.
         */
        $file = $this->flac();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('files.cover', ['Spanish', $file->filename]))
            ->assertNotFound();
    }

    public function test_the_endpoint_returns_304_for_an_unchanged_file(): void
    {
        // This is where cover bytes are cached - an ETag rather than the application cache,
        // which is the database by default and has no business holding a BLOB per track.
        $file = $this->flac();
        $this->embedCover($file);

        $user = User::factory()->admin()->create();
        $url = route('files.cover', ['Spanish', $file->filename]);

        $etag = $this->actingAs($user)->get($url)->headers->get('ETag');

        $this->assertNotNull($etag);

        $this->actingAs($user)
            ->withHeaders(['If-None-Match' => $etag])
            ->get($url)
            ->assertStatus(304);
    }

    public function test_the_etag_changes_after_a_write(): void
    {
        // Otherwise a browser holding the old artwork would never see the new one, and
        // `immutable` would make that permanent for a week.
        $file = $this->flac();
        $this->embedCover($file);

        $user = User::factory()->admin()->create();
        $url = route('files.cover', ['Spanish', $file->filename]);

        $first = $this->actingAs($user)->get($url)->headers->get('ETag');

        app(MetadataWriter::class)->write($file, TrackMetadata::fromArray([
            'title' => 'Retagged',
            'artist' => 'Someone',
        ]));

        $this->assertNotSame($first, $this->actingAs($user)->get($url)->headers->get('ETag'));
    }

    public function test_artwork_is_never_cached_publicly(): void
    {
        // Library content behind a login must not sit in a shared proxy. Asserted by
        // directive rather than by the whole header string, because Symfony reorders it.
        $file = $this->flac();
        $this->embedCover($file);

        $header = (string) $this->actingAs(User::factory()->admin()->create())
            ->get(route('files.cover', ['Spanish', $file->filename]))
            ->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $header);
        $this->assertStringContainsString('max-age=604800', $header);
        $this->assertStringContainsString('immutable', $header);
        $this->assertStringNotContainsString('public', $header);
    }

    public function test_a_guest_cannot_fetch_artwork(): void
    {
        $file = $this->flac();
        $this->embedCover($file);

        $this->get(route('files.cover', ['Spanish', $file->filename]))
            ->assertRedirect(route('login'));
    }

    public function test_a_user_who_cannot_see_the_folder_cannot_fetch_its_artwork(): void
    {
        // A cover is content, so it needs the same access as the file it came from.
        $file = $this->flac();
        $this->embedCover($file);

        $this->actingAs(User::factory()->withFolders(['Folk'])->create())
            ->get(route('files.cover', ['Spanish', $file->filename]))
            ->assertForbidden();
    }

    public function test_a_crafted_filename_cannot_reach_outside_the_folder(): void
    {
        // The filename is matched against the folder's real listing, never joined onto a
        // path.
        $this->actingAs(User::factory()->admin()->create())
            ->get('/files/Spanish/cover/'.urlencode('..%2F..%2F.env'))
            ->assertNotFound();
    }

    // ------------------------------------------------------------- public share

    public function test_a_single_track_share_serves_the_tracks_own_artwork(): void
    {
        $file = $this->flac('song.flac');
        $this->embedCover($file);

        $share = Share::factory()->track('Spanish', 'song.flac')->create();

        $this->get(route('share.cover', $share->token))
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg')
            // no-store, unlike the authenticated endpoint: this URL is behind a revocable
            // link, and cached artwork would outlive the revocation.
            ->assertHeader('cache-control', 'no-store, private');
    }

    public function test_the_public_page_shows_the_artwork_for_a_track_share(): void
    {
        $file = $this->flac('song.flac');
        $this->embedCover($file);

        $share = Share::factory()->track('Spanish', 'song.flac')->create();

        $this->get(route('share.show', $share->token))
            ->assertOk()
            ->assertSee(route('share.cover', $share->token), escape: false);
    }

    public function test_the_public_page_falls_back_to_the_tile_when_there_is_no_artwork(): void
    {
        $this->flac('song.flac');

        $share = Share::factory()->track('Spanish', 'song.flac')->create();

        // No image request at all, rather than one that 404s: for a single file the answer
        // is one cheap cached read, so the page already knows.
        $this->get(route('share.show', $share->token))
            ->assertOk()
            ->assertDontSee(route('share.cover', $share->token), escape: false);
    }

    public function test_a_folder_share_never_serves_artwork(): void
    {
        /*
         * A folder is many files with potentially many different covers, and picking one to
         * stand for the whole folder would be a guess. The generated tile at least never
         * claims to be anything but derived from the name.
         */
        $file = $this->flac('song.flac');
        $this->embedCover($file);

        $share = Share::factory()->folder('Spanish')->create();

        $this->get(route('share.cover', $share->token))->assertNotFound();
        $this->get(route('share.show', $share->token))
            ->assertOk()
            ->assertDontSee(route('share.cover', $share->token), escape: false);
    }

    public function test_artwork_from_a_dead_link_is_refused(): void
    {
        $file = $this->flac('song.flac');
        $this->embedCover($file);

        $revoked = Share::factory()->track('Spanish', 'song.flac')->revoked()->create();

        $this->get(route('share.cover', $revoked->token))->assertNotFound();
    }
}
