<?php

namespace Tests\Feature\Metadata;

use App\Enums\Permission;
use App\Models\User;
use App\Services\Metadata\FlacTagWriter;
use App\Services\Metadata\Metaflac;
use App\Support\LibraryFile;
use App\Support\LibraryFolder;
use App\Support\TrackMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** The read-only preview. */
class MetadataViewerTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/viewer-'.getmypid());

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

    private function flac(string $filename = 'track.flac'): LibraryFile
    {
        $ffmpeg = (new ExecutableFinder)->find('ffmpeg');

        if ($ffmpeg === null) {
            $this->markTestSkipped('ffmpeg is needed to generate a real FLAC.');
        }

        $path = $this->root.'/Spanish/'.$filename;

        $process = new Process([
            $ffmpeg, '-y', '-loglevel', 'error', '-f', 'lavfi',
            '-i', 'anullsrc=r=48000:cl=stereo', '-t', '2', '-sample_fmt', 's32', '-c:a', 'flac', $path,
        ], timeout: 60);

        $process->run();

        if (! $process->isSuccessful()) {
            $this->markTestSkipped('ffmpeg could not produce a FLAC.');
        }

        return new LibraryFile(new LibraryFolder('Spanish'), $filename);
    }

    private function tag(LibraryFile $file): void
    {
        app(FlacTagWriter::class)->write($this->root.'/'.$file->path(), TrackMetadata::fromArray([
            'title' => 'Você já sabe',
            'artist' => 'Anitta & Los Brasileros',
            'album' => 'EQUILIBRIVM II',
            'year' => '2026',
            'isrc' => 'USUG12607502',
            'recordingId' => 'e367ed04-41d7-4780-8bce-b1fabb4e9c08',
        ]));
    }

    private function viewer(?User $user = null)
    {
        return Livewire::actingAs($user ?? User::factory()->admin()->create())
            ->test('pages::files.metadata-viewer');
    }

    public function test_it_shows_the_tags_a_file_actually_carries(): void
    {
        $file = $this->flac();
        $this->tag($file);

        $this->viewer()
            ->call('open', 'Spanish', $file->filename)
            ->assertSet('unreadable', false)
            ->assertSee('Você já sabe')
            ->assertSee('Anitta &amp; Los Brasileros', escape: false)
            ->assertSee('EQUILIBRIVM II')
            ->assertSee('USUG12607502');
    }

    public function test_it_shows_the_stream_facts(): void
    {
        // The reason this is a preview and not just a rerun of the editor: sample rate, bit
        // depth and channel count exist nowhere else in the app.
        $this->viewer()
            ->call('open', 'Spanish', $this->flac()->filename)
            ->assertSee('48 kHz · 24-bit · Stereo', escape: false);
    }

    public function test_it_says_when_a_file_has_been_tagged_from_musicbrainz(): void
    {
        // The difference between tags from a database and tags from whatever YouTube called
        // the video.
        $file = $this->flac();
        $this->tag($file);

        $this->viewer()
            ->call('open', 'Spanish', $file->filename)
            ->assertSee('Tagged from MusicBrainz', escape: false)
            ->assertDontSee('Not matched to MusicBrainz', escape: false);
    }

    public function test_it_says_when_a_file_has_tags_but_no_musicbrainz_ids(): void
    {
        $file = $this->flac();

        app(FlacTagWriter::class)->write($this->root.'/'.$file->path(), TrackMetadata::fromArray([
            'title' => 'Some Track',
            'artist' => 'Someone',
        ]));

        $this->viewer()
            ->call('open', 'Spanish', $file->filename)
            ->assertSee('Not matched to MusicBrainz', escape: false);
    }

    public function test_an_untagged_file_is_told_what_to_do_next(): void
    {
        // A fresh download. Fifteen empty rows would be worse than a sentence.
        $this->viewer()
            ->call('open', 'Spanish', $this->flac()->filename)
            ->assertSee('This file has no tags yet', escape: false);
    }

    public function test_an_unreadable_file_says_so_rather_than_rendering_nothing(): void
    {
        Storage::disk('music')->put('Spanish/broken.flac', 'not audio at all');

        $this->viewer()
            ->call('open', 'Spanish', 'broken.flac')
            ->assertSet('unreadable', true)
            ->assertSee('could not be read', escape: false);
    }

    public function test_the_cover_is_only_offered_when_the_file_has_one(): void
    {
        /*
         * Checked server-side here, unlike in a listing. For one file the answer is a single
         * cached read, so the modal can render the "no embedded artwork" line rather than an
         * image request that 404s.
         */
        $file = $this->flac();

        $component = $this->viewer()->call('open', 'Spanish', $file->filename);

        $this->assertNull($component->instance()->coverUrl);
        $component->assertSee('no embedded artwork', escape: false);
    }

    public function test_the_cover_url_appears_once_artwork_is_embedded(): void
    {
        $file = $this->flac();

        $jpeg = $this->root.'/cover.jpg';
        $ffmpeg = (new ExecutableFinder)->find('ffmpeg');
        (new Process([$ffmpeg, '-y', '-loglevel', 'error', '-f', 'lavfi', '-i', 'color=c=red:s=200x200', '-frames:v', '1', $jpeg]))->run();

        app(Metaflac::class)->run(['--import-picture-from='.$jpeg, $this->root.'/'.$file->path()]);

        $component = $this->viewer()->call('open', 'Spanish', $file->filename);

        $this->assertSame(
            route('files.cover', ['Spanish', $file->filename]),
            $component->instance()->coverUrl,
        );

        $component->assertSee('embedded artwork', escape: false);
    }

    public function test_a_user_who_cannot_see_the_folder_cannot_preview_its_files(): void
    {
        $file = $this->flac();

        $this->viewer(User::factory()->withFolders(['Folk'])->create())
            ->call('open', 'Spanish', $file->filename)
            ->assertForbidden();
    }

    public function test_previewing_needs_no_edit_permission(): void
    {
        /*
         * Read-only: a file's own tags reveal nothing the listing does not already show, and
         * requiring the Edit permission to LOOK would make the feature useless to exactly the
         * people who need to check what they have.
         */
        $file = $this->flac();
        $this->tag($file);

        $this->viewer(User::factory()->without([Permission::Edit])->create())
            ->call('open', 'Spanish', $file->filename)
            ->assertSet('unreadable', false)
            ->assertSee('Você já sabe');
    }

    public function test_the_edit_button_is_hidden_without_the_edit_permission(): void
    {
        $file = $this->flac();
        $this->tag($file);

        $this->viewer(User::factory()->without([Permission::Edit])->create())
            ->call('open', 'Spanish', $file->filename)
            ->assertDontSee('Edit metadata', escape: false);
    }

    public function test_the_edit_button_hands_off_to_the_editor(): void
    {
        // So the two chain without making the user reopen the row menu.
        $file = $this->flac();
        $this->tag($file);

        $this->viewer()
            ->call('open', 'Spanish', $file->filename)
            ->call('edit')
            ->assertDispatched('metadata-edit');
    }

    public function test_a_crafted_filename_cannot_be_previewed(): void
    {
        $this->viewer()
            ->call('open', 'Spanish', '../../.env')
            ->assertNotFound();
    }

    public function test_the_listing_offers_a_cover_url_for_every_taggable_row(): void
    {
        // The listing does no disk work: it emits a cover URL for every FLAC without knowing
        // which have artwork. This asserts the URLs are there and compiled.
        $this->flac('one.flac');
        $this->flac('two.flac');

        $html = Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->html();

        $this->assertStringContainsString('files/Spanish/cover/one.flac', $html);
        $this->assertStringContainsString('files/Spanish/cover/two.flac', $html);
        $this->assertStringNotContainsString('@js(', $html);

        // And the row action is wired with real arguments.
        $this->assertStringContainsString("filename: 'one.flac'", $html);
    }

    public function test_a_non_taggable_file_gets_no_cover_url(): void
    {
        // An mp3 is listed and moved like anything else, but Minizo has no reader for its
        // tags, so requesting artwork for it would always 404.
        Storage::disk('music')->put('Spanish/legacy.mp3', 'audio');

        $html = Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->html();

        $this->assertStringNotContainsString('cover/legacy.mp3', $html);
    }
}
