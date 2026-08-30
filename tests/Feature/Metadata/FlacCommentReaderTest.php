<?php

namespace Tests\Feature\Metadata;

use App\Services\Metadata\AudioTagReader;
use App\Services\Metadata\FlacCommentReader;
use App\Services\Metadata\Metaflac;
use App\Support\LibraryFile;
use App\Support\LibraryFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** The narrow FLAC comment reader behind the Files screen's Genre column. */
class FlacCommentReaderTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        // A real directory: this reader opens a path with fopen, so Storage::fake's
        // in-memory adapter would give it nothing to read.
        $this->root = storage_path('framework/testing/comments-'.getmypid());

        if (! is_dir($this->root.'/Spanish')) {
            mkdir($this->root.'/Spanish', 0o777, true);
        }

        config()->set('filesystems.disks.music.root', $this->root);
        Storage::forgetDisk('music');
        Cache::flush();
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
            '-i', 'anullsrc=r=48000:cl=stereo', '-t', '1',
            '-c:a', 'flac', $path,
        ], timeout: 60);

        $process->run();

        if (! $process->isSuccessful() || ! file_exists($path)) {
            $this->markTestSkipped('ffmpeg could not produce a FLAC: '.$process->getErrorOutput());
        }

        return new LibraryFile(new LibraryFolder('Spanish'), $filename);
    }

    /**
     * @param  array<string, string>  $tags
     * @param  array<string, string>  $append  Added without removing, producing a second value.
     */
    private function tag(LibraryFile $file, array $tags, array $append = []): void
    {
        $arguments = [$this->binary('metaflac'), Metaflac::NO_TRANSCODE];

        foreach ($tags as $key => $value) {
            $arguments[] = '--remove-tag='.$key;
            $arguments[] = '--set-tag='.$key.'='.$value;
        }

        // A bare --set-tag appends, which is how a second value is added.
        foreach ($append as $key => $value) {
            $arguments[] = '--set-tag='.$key.'='.$value;
        }

        $arguments[] = $this->root.'/'.$file->path();

        $process = new Process($arguments, timeout: 60);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->markTestSkipped('metaflac could not tag the file: '.$process->getErrorOutput());
        }
    }

    private function reader(): FlacCommentReader
    {
        return app(FlacCommentReader::class);
    }

    // ------------------------------------------------------------------- reading

    #[Test]
    public function it_reads_a_tag_metaflac_wrote(): void
    {
        $file = $this->flac();
        $this->tag($file, ['GENRE' => 'Reggaeton', 'ARTIST' => 'Anitta']);

        $this->assertSame('Reggaeton', $this->reader()->value($file, 'GENRE'));
        $this->assertSame('Anitta', $this->reader()->value($file, 'ARTIST'));
    }

    #[Test]
    public function keys_are_matched_case_insensitively(): void
    {
        $file = $this->flac();
        $this->tag($file, ['GENRE' => 'Latin']);

        // Vorbis field names are conventionally upper-case but the spec does not require it,
        // and files in the wild carry both.
        $this->assertSame('Latin', $this->reader()->value($file, 'genre'));
        $this->assertSame('Latin', $this->reader()->value($file, 'Genre'));
    }

    #[Test]
    public function it_reads_multi_byte_values_intact(): void
    {
        $file = $this->flac();
        $this->tag($file, ['GENRE' => 'Música Popular', 'TITLE' => 'Você Já Sabe']);

        // Vorbis comments are UTF-8 by definition, and this reader does no conversion -
        // which is the correct amount, and the bug that bit the tag WRITER earlier.
        $this->assertSame('Música Popular', $this->reader()->value($file, 'GENRE'));
        $this->assertSame('Você Já Sabe', $this->reader()->value($file, 'TITLE'));
    }

    #[Test]
    public function an_untagged_file_yields_nothing(): void
    {
        $file = $this->flac();

        // A valid FLAC that simply has no GENRE. Must be null, not an error.
        $this->assertNull($this->reader()->value($file, 'GENRE'));
    }

    #[Test]
    public function a_missing_file_yields_nothing(): void
    {
        $file = new LibraryFile(new LibraryFolder('Spanish'), 'does-not-exist.flac');

        $this->assertSame([], $this->reader()->all($file));
    }

    #[Test]
    public function a_file_that_is_not_a_flac_yields_nothing(): void
    {
        file_put_contents($this->root.'/Spanish/fake.flac', 'this is plainly not audio');

        // The extension says FLAC; the magic bytes say otherwise. Same posture as
        // AudioTagReader - an unreadable file is an empty result, never an exception.
        $this->assertSame(
            [],
            $this->reader()->all(new LibraryFile(new LibraryFolder('Spanish'), 'fake.flac')),
        );
    }

    #[Test]
    public function a_format_with_no_vorbis_comments_is_not_even_opened(): void
    {
        file_put_contents($this->root.'/Spanish/song.mp3', 'x');

        // An mp3 is listed like anything else and has no comment block to read, so the
        // reader short-circuits on the format rather than parsing bytes to find that out.
        $this->assertSame(
            [],
            $this->reader()->all(new LibraryFile(new LibraryFolder('Spanish'), 'song.mp3')),
        );
    }

    #[Test]
    public function it_reads_several_fields_in_one_pass(): void
    {
        $file = $this->flac();

        $this->tag($file, [
            'GENRE' => 'Reggaeton',
            'MUSICBRAINZ_TRACKID' => '11111111-2222-3333-4444-555555555555',
            'MUSICBRAINZ_ALBUMID' => '66666666-7777-8888-9999-000000000000',
        ]);

        // One call, not three. The parse reads the whole comment block anyway, so a second
        // field is free, where three calls to valuesFor() repeat the entire batch.
        $fields = $this->reader()->fieldsFor(
            [$file],
            ['GENRE', 'MUSICBRAINZ_TRACKID', 'MUSICBRAINZ_ALBUMID', 'NEVER_SET'],
        );

        $this->assertSame([
            // Lists, because Vorbis comments are multi-valued and GENRE routinely is.
            'GENRE' => ['Reggaeton'],
            'MUSICBRAINZ_TRACKID' => ['11111111-2222-3333-4444-555555555555'],
            'MUSICBRAINZ_ALBUMID' => ['66666666-7777-8888-9999-000000000000'],
            // Requested but absent: present as a key with an empty list, so a caller can read
            // it without checking whether the key exists.
            'NEVER_SET' => [],
        ], $fields['track.flac']);
    }

    #[Test]
    public function a_batch_keeps_each_files_own_values(): void
    {
        $one = $this->flac('one.flac');
        $two = $this->flac('two.flac');

        $this->tag($one, ['GENRE' => 'Latin']);
        $this->tag($two, ['GENRE' => 'Folk']);

        // The obvious way to break batching is to let one file's parse leak into another's
        // row, and a single-file test can never catch it.
        $genres = $this->reader()->valuesFor([$one, $two], 'GENRE');

        $this->assertSame(['one.flac' => 'Latin', 'two.flac' => 'Folk'], $genres);
    }

    #[Test]
    public function a_batch_mixes_tagged_untagged_and_unreadable_files(): void
    {
        $tagged = $this->flac('tagged.flac');
        $this->tag($tagged, ['GENRE' => 'Latin']);

        $untagged = $this->flac('untagged.flac');

        file_put_contents($this->root.'/Spanish/song.mp3', 'x');
        $mp3 = new LibraryFile(new LibraryFolder('Spanish'), 'song.mp3');
        $missing = new LibraryFile(new LibraryFolder('Spanish'), 'gone.flac');

        $genres = $this->reader()->valuesFor([$tagged, $untagged, $mp3, $missing], 'GENRE');

        // A real value, a null, and two files that never reach the parser at all - the mp3
        // has no comment block by format, and the missing one has no fingerprint.
        $this->assertSame('Latin', $genres['tagged.flac']);
        $this->assertNull($genres['untagged.flac']);
        $this->assertArrayNotHasKey('song.mp3', $genres);
        $this->assertArrayNotHasKey('gone.flac', $genres);
    }

    #[Test]
    public function it_reads_every_value_of_a_repeated_field(): void
    {
        $file = $this->flac();

        // Repeating a field IS how Vorbis expresses a list, and it is what Picard and Minizo
        // both write for GENRE. Keeping only the first would silently discard genres a file
        // legitimately has.
        $this->tag($file, ['GENRE' => 'Pop'], append: ['GENRE' => 'Electronic']);

        $this->assertSame(['Pop', 'Electronic'], $this->reader()->list($file, 'GENRE'));

        // value() narrows to the first, for fields where only one makes sense.
        $this->assertSame('Pop', $this->reader()->value($file, 'GENRE'));
    }

    // ------------------------------------------------------------------- caching

    #[Test]
    public function re_tagging_a_file_through_the_app_invalidates_the_cached_read(): void
    {
        $file = $this->flac();
        $this->tag($file, ['GENRE' => 'Pop']);

        $this->assertSame('Pop', $this->reader()->value($file, 'GENRE'));

        $this->tag($file, ['GENRE' => 'Rock']);
        clearstatcache();

        // forget() is required rather than belt-and-braces: metaflac rewrites tags into the
        // file's existing padding, so a re-tag changes neither mtime nor size and the
        // fingerprint alone still matches. Asserting the stale read first pins that down.
        $this->assertSame('Pop', $this->reader()->value($file, 'GENRE'));

        app(AudioTagReader::class)->forget($file);

        $this->assertSame('Rock', $this->reader()->value($file, 'GENRE'));
    }

    // --------------------------------------------------------------------- speed

    #[Test]
    public function it_is_fast_enough_to_run_once_per_row(): void
    {
        $file = $this->flac();
        $this->tag($file, ['GENRE' => 'Reggaeton']);

        // Twenty distinct copies, so every read is a genuine cold parse. One file read twenty
        // times would measure the cache, and flushing inside the loop would measure the flush.
        // Read through valuesFor(), which is the call the Files screen makes.
        $files = [];

        for ($i = 0; $i < 20; $i++) {
            $name = "speed-{$i}.flac";
            copy($this->root.'/'.$file->path(), $this->root.'/Spanish/'.$name);
            $files[] = new LibraryFile(new LibraryFolder('Spanish'), $name);
        }

        Cache::flush();
        clearstatcache();

        $start = microtime(true);

        $genres = $this->reader()->valuesFor($files, 'GENRE');

        $perFile = ((microtime(true) - $start) / 20) * 1000;

        // Sanity: a fast run that read nothing would prove nothing.
        $this->assertCount(20, $genres);
        $this->assertSame('Reggaeton', $genres['speed-0.flac']);

        // A loose bar, set from measurement: a cold batched read lands around 10 ms per file
        // here against 27.7 ms for AudioTagReader. 20 ms leaves room for a slow CI disk but
        // still fails if this is routed back through getID3. Most of the 10 ms is Flysystem
        // stats and cache I/O; the parse alone is ~1.6 ms.
        $this->assertLessThan(
            20.0,
            $perFile,
            sprintf('a cold batched comment read took %.2f ms per file; getID3 is 27.7 ms', $perFile),
        );
    }
}
