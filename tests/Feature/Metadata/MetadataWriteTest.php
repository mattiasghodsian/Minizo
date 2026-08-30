<?php

namespace Tests\Feature\Metadata;

use App\Exceptions\LibraryException;
use App\Exceptions\MetadataException;
use App\Models\User;
use App\Services\Library\FileService;
use App\Services\Metadata\CoverArtEmbedder;
use App\Services\Metadata\MetadataWriter;
use App\Support\LibraryFile;
use App\Support\LibraryFolder;
use App\Support\TrackMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Kiwilan\Audio\Audio;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** The write path. */
class MetadataWriteTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        // A real directory, not Storage::fake's in-memory adapter: metaflac and
        // getID3 both need a path they can open.
        $this->root = storage_path('framework/testing/music-'.getmypid());

        if (! is_dir($this->root.'/Spanish')) {
            mkdir($this->root.'/Spanish', 0o777, true);
        }

        config()->set('filesystems.disks.music.root', $this->root);
        config()->set('minizo.library.disk', 'music');

        Storage::forgetDisk('music');

        $this->actingAs(User::factory()->admin()->create());
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            foreach (glob($this->root.'/*/*') ?: [] as $file) {
                @unlink($file);
            }
            foreach (glob($this->root.'/*') ?: [] as $dir) {
                @rmdir($dir);
            }
            @rmdir($this->root);
        }

        parent::tearDown();
    }

    private function metadata(array $overrides = []): TrackMetadata
    {
        return TrackMetadata::fromArray([
            'title' => 'Creep',
            'artist' => 'Radiohead',
            'album' => 'Pablo Honey',
            'year' => '1993',
            'trackNumber' => '2',
            'totalTracks' => 12,
            'isrc' => 'GBAYE9200369',
            'barcode' => '724388092302',
            'label' => 'Parlophone',
            'status' => 'Official',
            'country' => 'GB',
            'language' => 'eng',
            'genres' => ['alternative rock'],
            'releaseId' => '52fa0b53-4bad-4bbe-b23b-d82233500fc7',
            'recordingId' => '402b2ed6-d942-4d43-8ad4-f9ca9cc9db68',
            ...$overrides,
        ]);
    }

    /** Generate a tiny real FLAC, or skip. */
    private function realFlac(string $filename = 'input.flac'): LibraryFile
    {
        $ffmpeg = (new ExecutableFinder)->find('ffmpeg');

        if ($ffmpeg === null) {
            $this->markTestSkipped('ffmpeg is needed to generate a real FLAC.');
        }

        $path = $this->root.'/Spanish/'.$filename;

        $process = new Process([
            $ffmpeg, '-y', '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=mono',
            '-t', '1', '-c:a', 'flac', $path,
        ], timeout: 60);

        $process->run();

        if (! $process->isSuccessful() || ! file_exists($path)) {
            $this->markTestSkipped('ffmpeg could not produce a FLAC: '.$process->getErrorOutput());
        }

        Storage::disk('music')->assertExists('Spanish/'.$filename);

        return new LibraryFile(new LibraryFolder('Spanish'), $filename);
    }

    private function writer(): MetadataWriter
    {
        return app(MetadataWriter::class);
    }

    /** A 64x64 JPEG with real dimensions in its header, which is what metaflac needs. */
    private function realJpeg(): string
    {
        $ffmpeg = (new ExecutableFinder)->find('ffmpeg');

        if ($ffmpeg === null) {
            $this->markTestSkipped('ffmpeg is needed to generate a cover image.');
        }

        $path = $this->root.'/cover.jpg';

        $process = new Process([
            $ffmpeg, '-y', '-loglevel', 'error', '-f', 'lavfi',
            '-i', 'color=c=blue:s=64x64', '-frames:v', '1', $path,
        ], timeout: 60);

        $process->run();

        if (! $process->isSuccessful() || ! file_exists($path)) {
            $this->markTestSkipped('ffmpeg could not produce a JPEG: '.$process->getErrorOutput());
        }

        return (string) file_get_contents($path);
    }

    // ------------------------------------------------------------- round trip

    public function test_tags_are_written_and_can_be_read_back(): void
    {
        // Read back with kiwilan/php-audio rather than metaflac. The tags are written by
        // metaflac, so asserting with the same tool would only prove it agrees with itself.
        $file = $this->realFlac();

        $this->writer()->write($file, $this->metadata());

        $audio = Audio::read($this->root.'/'.$file->path());

        $this->assertSame('Creep', $audio->getTitle());
        $this->assertSame('Radiohead', $audio->getArtist());
        $this->assertSame('Pablo Honey', $audio->getAlbum());
        $this->assertSame('1993', (string) $audio->getYear());
        $this->assertSame('alternative rock', $audio->getGenre());
    }

    public function test_several_genres_are_written_as_separate_tags(): void
    {
        // Repeating a field is how the Vorbis spec expresses a list, and what Picard writes, so
        // a file Minizo tags stays interchangeable with one Picard tagged. Joining with a
        // delimiter into one tag produces a string no player can split.
        $file = $this->realFlac();

        $this->writer()->write($file, $this->metadata([
            'genres' => ['alternative rock', 'britpop', 'grunge'],
        ]));

        $lines = $this->vorbisLines($this->root.'/'.$file->path());

        $genres = array_values(array_filter(
            $lines,
            fn (string $line): bool => str_starts_with($line, 'GENRE='),
        ));

        $this->assertSame([
            'GENRE=alternative rock',
            'GENRE=britpop',
            'GENRE=grunge',
        ], $genres);
    }

    public function test_re_tagging_replaces_the_whole_genre_list(): void
    {
        // The writer removes ONCE and then sets each value. Removing inside the loop would
        // leave only the last genre; not removing at all would accumulate every past set.
        $file = $this->realFlac();

        $this->writer()->write($file, $this->metadata(['genres' => ['rock', 'britpop']]));
        $this->writer()->write($file, $this->metadata(['genres' => ['jazz']]));

        $genres = array_values(array_filter(
            $this->vorbisLines($this->root.'/'.$file->path()),
            fn (string $line): bool => str_starts_with($line, 'GENRE='),
        ));

        $this->assertSame(['GENRE=jazz'], $genres);
    }

    public function test_the_musicbrainz_identifiers_are_written_under_the_names_picard_uses(): void
    {
        /*
         * Not a detail. Writing these under Picard's names is what makes a file tagged
         * by Minizo and one tagged by Picard interchangeable - which is the entire
         * reason to carry MusicBrainz identifiers rather than invent our own.
         */
        $file = $this->realFlac();

        $this->writer()->write($file, $this->metadata());

        $tags = $this->vorbisTags($this->root.'/'.$file->path());

        $this->assertSame('402b2ed6-d942-4d43-8ad4-f9ca9cc9db68', $tags['MUSICBRAINZ_TRACKID'] ?? null);
        $this->assertSame('52fa0b53-4bad-4bbe-b23b-d82233500fc7', $tags['MUSICBRAINZ_ALBUMID'] ?? null);
        $this->assertSame('GBAYE9200369', $tags['ISRC'] ?? null);
        $this->assertSame('724388092302', $tags['BARCODE'] ?? null);
        $this->assertSame('Parlophone', $tags['LABEL'] ?? null);
    }

    public function test_a_non_ascii_title_is_stored_as_utf8_not_double_encoded(): void
    {
        // A real bug, found by tagging an actual track rather than a fixture. The first
        // implementation wrote through kiwilan/php-audio, whose getID3 backend defaults
        // tag_encoding to ISO-8859-1 and does not expose it, so "Você já sabe" landed as
        // "VocÃª jÃ¡ sabe". Every fixture here was ASCII, so the suite passed while the
        // feature was broken. Hence the assertion on bytes.
        $file = $this->realFlac();

        $this->writer()->write($file, $this->metadata([
            'title' => 'Você já sabe',
            'artist' => 'Anitta & Los Brasileros',
            'album' => 'EQUILIBRIVM II',
        ]));

        $tags = $this->vorbisTags($this->root.'/'.$file->path());

        $this->assertSame('Você já sabe', $tags['TITLE'] ?? null);
        $this->assertStringNotContainsString('Ã', (string) ($tags['TITLE'] ?? ''));
    }

    public function test_a_title_outside_latin1_survives(): void
    {
        // The case pre-encoding to ISO-8859-1 could never have fixed: there is no
        // Latin-1 representation of these characters at all.
        $file = $this->realFlac();

        $this->writer()->write($file, $this->metadata(['title' => '電台司令 — レディオヘッド']));

        $this->assertSame(
            '電台司令 — レディオヘッド',
            $this->vorbisTags($this->root.'/'.$file->path())['TITLE'] ?? null,
        );
    }

    public function test_re_tagging_does_not_accumulate_duplicate_fields(): void
    {
        /*
         * Vorbis comments are multi-valued, so a bare --set-tag APPENDS. Without the
         * remove-then-set per key, tagging a file three times leaves three TITLE fields
         * and players disagree about which one to show.
         */
        $file = $this->realFlac();

        $this->writer()->write($file, $this->metadata(['title' => 'First']));
        $this->writer()->write($file, $this->metadata(['title' => 'Second']));
        $this->writer()->write($file, $this->metadata(['title' => 'Third']));

        $titles = array_filter(
            explode("\n", $this->rawTags($this->root.'/'.$file->path())),
            fn (string $line): bool => str_starts_with($line, 'TITLE='),
        );

        $this->assertCount(1, $titles);
        $this->assertSame('TITLE=Third', trim(reset($titles)));
    }

    public function test_absent_fields_do_not_blank_what_the_file_already_had(): void
    {
        /*
         * The standalone case, and the one that would quietly destroy data. A recording
         * with no release has no album, label or barcode - writing those as empty
         * strings would replace whatever the file already carried with blank fields.
         */
        $file = $this->realFlac();

        $this->writer()->write($file, $this->metadata());
        $this->writer()->write($file, TrackMetadata::fromArray([
            'title' => 'Nude (Amplive remix)',
            'artist' => 'Radiohead',
            'standalone' => true,
        ]));

        $audio = Audio::read($this->root.'/'.$file->path());

        $this->assertSame('Nude (Amplive remix)', $audio->getTitle());
        // Still there from the first write.
        $this->assertSame('Pablo Honey', $audio->getAlbum());
    }

    // ------------------------------------------------------------------ guards

    public function test_a_non_flac_file_is_refused(): void
    {
        // The library lists mp3s and moves them like anything else; there is simply no
        // tag writer for them, and saying so beats writing something wrong.
        Storage::disk('music')->put('Spanish/song.mp3', 'not really audio');

        $this->expectException(MetadataException::class);

        $this->writer()->write(
            new LibraryFile(new LibraryFolder('Spanish'), 'song.mp3'),
            $this->metadata(),
        );
    }

    public function test_a_metadata_set_with_no_title_or_artist_is_refused(): void
    {
        // Writing a blank title would erase what the file had, which is worse than
        // doing nothing.
        $file = $this->realFlac();

        $this->expectException(MetadataException::class);

        $this->writer()->write($file, TrackMetadata::fromArray([]));
    }

    public function test_a_file_that_has_gone_is_reported_not_fatal(): void
    {
        $this->expectException(LibraryException::class);

        $this->writer()->write(
            new LibraryFile(new LibraryFolder('Spanish'), 'ghost.flac'),
            $this->metadata(),
        );
    }

    public function test_it_takes_the_same_lock_the_library_writes_use(): void
    {
        /*
         * Not a second lock of its own. metaflac rewrites the whole file, so a
         * concurrent move or delete on the same path can leave it torn - the lock is
         * only useful if both sides agree on the key.
         */
        $file = $this->realFlac();

        $lock = Cache::lock('minizo:file:spanish/input.flac', 15);
        $this->assertTrue($lock->get());

        try {
            $this->writer()->write($file, $this->metadata());
            $this->fail('The write should have been refused while locked.');
        } catch (LibraryException $e) {
            $this->assertStringContainsString('being modified', $e->getMessage());
        } finally {
            $lock->release();
        }
    }

    // ------------------------------------------------------------------ rename

    public function test_it_renames_to_artist_title_when_asked(): void
    {
        $file = $this->realFlac();

        $result = $this->writer()->write($file, $this->metadata(), rename: true);

        $this->assertTrue($result->renamed);
        $this->assertSame('Radiohead - Creep.flac', $result->file->filename);
        Storage::disk('music')->assertExists('Spanish/Radiohead - Creep.flac');
        Storage::disk('music')->assertMissing('Spanish/input.flac');
    }

    public function test_the_suggested_filename_drops_characters_a_windows_host_rejects(): void
    {
        // The music disk is a bind mount from a Windows host, so ":" and "?" in a title
        // make the file unwritable there even though the container is happy.
        $metadata = $this->metadata(['title' => 'Who? Me: Yes/No', 'artist' => 'A*B']);

        $this->assertSame('AB - Who Me YesNo.flac', $metadata->suggestedFilename('flac'));
    }

    public function test_a_rename_collision_is_a_warning_not_a_failed_write(): void
    {
        /*
         * The tags are already on disk by the time the rename runs. Reporting failure
         * would invite the user to run the whole thing again, and the usual cause is the
         * most benign one there is - a file with that name already exists.
         */
        $file = $this->realFlac();
        Storage::disk('music')->put('Spanish/Radiohead - Creep.flac', 'taken');

        $result = $this->writer()->write($file, $this->metadata(), rename: true);

        $this->assertFalse($result->renamed);
        $this->assertTrue($result->hasWarnings());
        $this->assertSame('input.flac', $result->file->filename);

        // And the tags landed regardless.
        $this->assertSame('Creep', Audio::read($this->root.'/Spanish/input.flac')->getTitle());
    }

    public function test_no_rename_happens_when_the_name_is_already_right(): void
    {
        $file = $this->realFlac('Radiohead - Creep.flac');

        $result = $this->writer()->write($file, $this->metadata(), rename: true);

        $this->assertFalse($result->renamed);
        $this->assertSame([], $result->warnings);
    }

    // --------------------------------------------------------------- cover art

    public function test_a_cover_art_failure_does_not_lose_the_tags(): void
    {
        /*
         * The most important behaviour in this file. The tags are what the user asked
         * for; the artwork depends on a second HTTP request and an external binary. A
         * failed embed comes back as a warning on a successful result.
         */
        $file = $this->realFlac();

        $this->mock(CoverArtEmbedder::class)
            ->shouldReceive('embed')
            ->andThrow(MetadataException::coverToolUnavailable());

        $result = $this->writer()->write($file, $this->metadata([
            'coverArtUrl' => 'https://coverartarchive.org/release/abc/1.jpg',
        ]));

        $this->assertTrue($result->hasWarnings());
        $this->assertStringContainsString('metaflac', (string) $result->warningText());

        $this->assertSame('Creep', Audio::read($this->root.'/'.$file->path())->getTitle());
    }

    public function test_no_cover_url_is_not_a_failure(): void
    {
        // A standalone recording has no release, so there is nothing for Cover Art
        // Archive to key on. That is an absence, not an error.
        $file = $this->realFlac();

        $result = $this->writer()->write($file, $this->metadata(['coverArtUrl' => null]));

        $this->assertFalse($result->hasWarnings());
    }

    public function test_the_allow_list_rejects_a_look_alike_host(): void
    {
        // The suffix match is dot-anchored, so a host that merely ENDS with the allowed
        // string is not the allowed host.
        foreach ([
            'https://evilcoverartarchive.org/x.jpg',
            'https://coverartarchive.org.evil.com/x.jpg',
            'https://coverartarchive.org@evil.com/x.jpg',
        ] as $url) {
            $this->assertFalse(
                TrackMetadata::isAllowedCoverHost($url),
                "[{$url}] should have been rejected",
            );
        }

        $this->assertTrue(
            TrackMetadata::isAllowedCoverHost('https://ia800207.us.archive.org/x.jpg'),
            'a real archive.org storage host must still pass, or redirects break',
        );
    }

    public function test_a_cover_url_from_an_unexpected_host_is_dropped_on_the_way_in(): void
    {
        // The one client-supplied field that becomes an outbound request from the server. An
        // allow-list, because a scheme check would still permit http://169.254.169.254/.
        foreach ([
            'http://169.254.169.254/latest/meta-data/',
            'http://localhost:6379/',
            'https://evil.example.com/x.jpg',
            'file:///etc/passwd',
        ] as $url) {
            $this->assertNull(
                TrackMetadata::fromArray(['title' => 'x', 'coverArtUrl' => $url])->coverArtUrl,
                "[{$url}] should have been rejected",
            );
        }

        $this->assertSame(
            'https://coverartarchive.org/release/abc/1.jpg',
            TrackMetadata::fromArray([
                'title' => 'x',
                'coverArtUrl' => 'https://coverartarchive.org/release/abc/1.jpg',
            ])->coverArtUrl,
        );
    }

    public function test_a_real_cover_is_embedded_as_a_single_picture_block(): void
    {
        // metaflac appends pictures. Without the --remove first, re-tagging three times leaves
        // three covers embedded and the file grows every time.
        if ((new ExecutableFinder)->find('metaflac') === null) {
            $this->markTestSkipped('metaflac is needed for the cover round trip.');
        }

        $file = $this->realFlac();

        // A real JPEG, generated rather than inlined as base64: metaflac reads the image's
        // dimensions out of the file and refuses anything it cannot parse, so a minimal
        // hand-written header fails here while a genuine one works.
        Http::fake([
            'coverartarchive.org/*' => Http::response(
                $this->realJpeg(),
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);

        $url = 'https://coverartarchive.org/release/52fa0b53-4bad-4bbe-b23b-d82233500fc7/1.jpg';

        $this->writer()->write($file, $this->metadata(['coverArtUrl' => $url]));
        $this->writer()->write($file, $this->metadata(['coverArtUrl' => $url]));

        $this->assertSame(1, $this->pictureBlockCount($this->root.'/'.$file->path()));
    }

    // ------------------------------------------------------------------- cache

    public function test_the_folders_cached_listing_is_dropped_after_a_write(): void
    {
        /*
         * Proven by making the cache demonstrably stale first, rather than by comparing
         * file sizes - a FLAC's padding block absorbs a small tag change, so the byte
         * count often does not move even though the file did.
         */
        $file = $this->realFlac();
        $files = app(FileService::class);
        $folder = new LibraryFolder('Spanish');

        // Warm the listing, then add a file behind the service's back so the cache is
        // now known to be wrong.
        $this->assertCount(1, $files->all($folder));
        Storage::disk('music')->put('Spanish/appeared-later.flac', 'x');
        $this->assertCount(1, $files->all($folder), 'the listing should still be cached here');

        $this->writer()->write($file, $this->metadata());

        $this->assertCount(2, $files->all($folder));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Every exported "KEY=value" line, in file order.
     *
     * @return array<int, string>
     */
    private function vorbisLines(string $path): array
    {
        $binary = (new ExecutableFinder)->find('metaflac');

        if ($binary === null) {
            $this->markTestSkipped('metaflac is needed to read the tags back.');
        }

        $process = new Process([$binary, '--no-utf8-convert', '--export-tags-to=-', $path], timeout: 30);
        $process->run();

        return array_values(array_filter(
            array_map('trim', explode("\n", $process->getOutput())),
            fn (string $line): bool => str_contains($line, '='),
        ));
    }

    /**
     * @return array<string, string>
     */
    private function vorbisTags(string $path): array
    {
        $binary = (new ExecutableFinder)->find('metaflac');

        if ($binary === null) {
            $this->markTestSkipped('metaflac is needed to read the tags back.');
        }

        // --no-utf8-convert on the way out too, so what is asserted is exactly the
        // bytes on disk rather than a re-transcoding of them.
        $process = new Process([$binary, '--no-utf8-convert', '--export-tags-to=-', $path], timeout: 30);
        $process->run();

        $tags = [];

        foreach (explode("\n", $process->getOutput()) as $line) {
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $tags[strtoupper(trim($key))] = trim($value);
            }
        }

        return $tags;
    }

    /** The raw --export-tags-to output, one KEY=value per line, duplicates included. */
    private function rawTags(string $path): string
    {
        $binary = (new ExecutableFinder)->find('metaflac');

        if ($binary === null) {
            $this->markTestSkipped('metaflac is needed to read the tags back.');
        }

        $process = new Process([$binary, '--no-utf8-convert', '--export-tags-to=-', $path], timeout: 30);
        $process->run();

        return $process->getOutput();
    }

    private function pictureBlockCount(string $path): int
    {
        $binary = (new ExecutableFinder)->find('metaflac');

        $process = new Process([$binary, '--list', '--block-type=PICTURE', $path], timeout: 30);
        $process->run();

        return substr_count($process->getOutput(), 'type: 6 (PICTURE)');
    }
}
