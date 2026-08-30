<?php

namespace Tests\Feature\Metadata;

use App\Enums\Permission;
use App\Models\User;
use App\Services\Metadata\MetadataWriter;
use App\Services\Metadata\MetadataWriteResult;
use App\Support\LibraryFile;
use App\Support\LibraryFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\ReadsFixtures;
use Tests\TestCase;

/** The three-step modal. */
class MetadataEditorTest extends TestCase
{
    use ReadsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $disk = Storage::fake('music');
        $disk->makeDirectory('Spanish');
        $disk->put('Spanish/Radiohead - Creep (Official Video).flac', 'audio');
        $disk->put('Spanish/legacy.mp3', 'audio');

        config()->set('minizo.musicbrainz.min_request_interval', 0);

        RateLimiter::clear('musicbrainz-search:1');
    }

    private function fakeMusicBrainz(): void
    {
        Http::fake([
            'musicbrainz.org/ws/2/release/*' => Http::response($this->musicBrainzFixture('release-lookup')),
            'musicbrainz.org/ws/2/release?*' => Http::response($this->musicBrainzFixture('release-search')),
            'musicbrainz.org/ws/2/recording?*' => Http::response(['recordings' => []]),
            'musicbrainz.org/ws/2/recording/*' => Http::response([
                'id' => 'c5b644d0-c748-44c0-bebc-0f58140aaa83',
                'title' => 'Nude (Amplive remix)',
                'length' => 188000,
                'artist-credit' => [['name' => 'Radiohead']],
            ]),
            'coverartarchive.org/*' => Http::response($this->musicBrainzFixture('coverart')),
        ]);
    }

    private function editor(?User $user = null)
    {
        return Livewire::actingAs($user ?? User::factory()->admin()->create())
            ->test('pages::files.metadata-editor');
    }

    private const FILE = 'Radiohead - Creep (Official Video).flac';

    // ------------------------------------------------------------------ opening

    public function test_opening_pre_fills_the_artist_and_title_from_the_filename(): void
    {
        /*
         * The filename is what the downloader wrote - "Artist - Title.flac" - carrying
         * whatever YouTube furniture came with it. Pre-filling the cleaned form is the
         * difference between one click and retyping both fields.
         */
        $this->editor()
            ->call('open', 'Spanish', self::FILE)
            ->assertSet('artist', 'Radiohead')
            ->assertSet('title', 'Creep')
            ->assertSet('step', 1);
    }

    public function test_a_user_without_the_edit_permission_cannot_open_it(): void
    {
        $this->editor(User::factory()->without([Permission::Edit])->create())
            ->call('open', 'Spanish', self::FILE)
            ->assertForbidden();
    }

    public function test_a_user_who_cannot_see_the_folder_cannot_open_it(): void
    {
        $this->editor(User::factory()->withFolders(['Folk'])->create())
            ->call('open', 'Spanish', self::FILE)
            ->assertForbidden();
    }

    public function test_a_non_flac_file_is_refused_with_a_message(): void
    {
        // The row menu already dims the action for these, but the action itself must
        // still refuse - the menu is a hint, not a gate.
        $this->editor()
            ->call('open', 'Spanish', 'legacy.mp3')
            ->assertSet('filename', '');
    }

    public function test_a_crafted_filename_cannot_escape_the_folder(): void
    {
        // Only names cross the wire, and they are re-resolved against the folder's real
        // contents - so a traversal string resolves to nothing.
        $this->editor()
            ->call('open', 'Spanish', '../../.env')
            ->assertNotFound();
    }

    // ------------------------------------------------------------------- step 1

    public function test_searching_lists_ranked_candidates(): void
    {
        $this->fakeMusicBrainz();

        $component = $this->editor()
            ->call('open', 'Spanish', self::FILE)
            ->call('search')
            ->assertSet('searched', true)
            ->assertSet('step', 1);

        $this->assertNotEmpty($component->instance()->results);

        // The single ranks above the album, which is the ranker's main job.
        $this->assertSame('Single', $component->instance()->results[0]->type->label());
    }

    public function test_the_search_is_rate_limited_per_user(): void
    {
        /*
         * The client already enforces one request per second instance-wide, but that is
         * a queue rather than a budget: without this, one user holding the button fills
         * it and everyone else's lookups sit behind theirs.
         */
        $this->fakeMusicBrainz();
        config()->set('minizo.musicbrainz.user_rate_limit', 2);

        $component = $this->editor()->call('open', 'Spanish', self::FILE);

        $component->call('search')->assertHasNoErrors();
        $component->call('search')->assertHasNoErrors();
        $component->call('search')->assertHasErrors('title');
    }

    public function test_no_results_says_so_rather_than_showing_an_empty_table(): void
    {
        Http::fake(['musicbrainz.org/*' => Http::response(['releases' => [], 'recordings' => []])]);

        $this->editor()
            ->call('open', 'Spanish', self::FILE)
            ->call('search')
            ->assertSet('searched', true)
            ->assertSee('Nothing on MusicBrainz matched', escape: false);
    }

    public function test_force_release_id_skips_the_search_entirely(): void
    {
        // For the case where the user already found the right release on
        // musicbrainz.org, which for an obscure track beats any query we could build.
        $this->fakeMusicBrainz();

        $this->editor()
            ->call('open', 'Spanish', self::FILE)
            ->set('forceReleaseId', true)
            ->set('releaseId', '52fa0b53-4bad-4bbe-b23b-d82233500fc7')
            ->call('search')
            ->assertHasNoErrors()
            ->assertSet('step', 2);

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/release?'));
    }

    public function test_a_release_id_that_is_not_an_mbid_is_reported_on_the_field(): void
    {
        $this->fakeMusicBrainz();

        $this->editor()
            ->call('open', 'Spanish', self::FILE)
            ->set('forceReleaseId', true)
            ->set('releaseId', 'not-a-uuid')
            ->call('search')
            ->assertHasErrors('releaseId')
            ->assertSet('step', 1);
    }

    // ------------------------------------------------------------------- step 2

    public function test_picking_a_multi_track_release_goes_to_the_track_picker(): void
    {
        $this->fakeMusicBrainz();

        $component = $this->editor()
            ->call('open', 'Spanish', self::FILE)
            ->call('pick', '52fa0b53-4bad-4bbe-b23b-d82233500fc7', 'single')
            ->assertSet('step', 2);

        // The fixture is a four-track 12" single.
        $this->assertCount(4, $component->instance()->trackRows);
    }

    public function test_the_best_matching_track_is_flagged(): void
    {
        $this->fakeMusicBrainz();

        $component = $this->editor()
            ->call('open', 'Spanish', self::FILE)
            ->call('pick', '52fa0b53-4bad-4bbe-b23b-d82233500fc7', 'single');

        $flagged = array_filter($component->instance()->trackRows, fn ($track): bool => $track->isBestMatch);

        $this->assertCount(1, $flagged);
        $this->assertSame('Creep (live)', reset($flagged)->title);
    }

    public function test_a_single_track_release_skips_the_picker(): void
    {
        // Stopping to confirm the only option is friction with no decision in it.
        $release = $this->musicBrainzFixture('release-lookup');
        $release['media'][0]['tracks'] = [$release['media'][0]['tracks'][0]];
        $release['media'][0]['track-count'] = 1;

        Http::fake([
            'musicbrainz.org/ws/2/release/*' => Http::response($release),
            'coverartarchive.org/*' => Http::response($this->musicBrainzFixture('coverart')),
        ]);

        $this->editor()
            ->call('open', 'Spanish', self::FILE)
            ->call('pick', '52fa0b53-4bad-4bbe-b23b-d82233500fc7', 'single')
            ->assertSet('step', 3);
    }

    // ------------------------------------------------------------------- step 3

    public function test_picking_a_track_resolves_the_full_tag_set(): void
    {
        $this->fakeMusicBrainz();

        $component = $this->editor()
            ->call('open', 'Spanish', self::FILE)
            ->call('pick', '52fa0b53-4bad-4bbe-b23b-d82233500fc7', 'single')
            ->call('pickTrack', 0, 0)
            ->assertSet('step', 3);

        $resolved = $component->instance()->resolved;

        $this->assertSame('Creep (live)', $resolved->title);
        $this->assertSame('Radiohead', $resolved->artist);
        $this->assertSame('Parlophone', $resolved->label);
        $this->assertStringStartsWith('https://coverartarchive.org/', (string) $resolved->coverArtUrl);
    }

    public function test_a_standalone_recording_skips_step_two_and_has_no_cover(): void
    {
        /*
         * The whole reason ReleaseType::Standalone exists. A standalone recording IS the
         * track, so there is nothing to pick - and Cover Art Archive is keyed by release,
         * so there is nowhere for artwork to live.
         */
        $this->fakeMusicBrainz();

        $component = $this->editor()
            ->call('open', 'Spanish', self::FILE)
            ->call('pick', 'c5b644d0-c748-44c0-bebc-0f58140aaa83', 'standalone')
            ->assertSet('step', 3)
            ->assertSet('tracks', []);

        $resolved = $component->instance()->resolved;

        $this->assertTrue($resolved->standalone);
        $this->assertNull($resolved->coverArtUrl);
        $this->assertNull($resolved->album);
        $this->assertNull($resolved->releaseId);

        // And it says why, rather than leaving a blank square.
        $component->assertSee('standalone recordings have no release', escape: false);
    }

    public function test_the_cover_art_archive_is_not_called_for_a_standalone(): void
    {
        $this->fakeMusicBrainz();

        $this->editor()
            ->call('open', 'Spanish', self::FILE)
            ->call('pick', 'c5b644d0-c748-44c0-bebc-0f58140aaa83', 'standalone');

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'coverartarchive.org'));
    }

    public function test_previous_from_step_three_returns_to_the_picker_when_there_was_one(): void
    {
        $this->fakeMusicBrainz();

        $this->editor()
            ->call('open', 'Spanish', self::FILE)
            ->call('pick', '52fa0b53-4bad-4bbe-b23b-d82233500fc7', 'single')
            ->call('pickTrack', 0, 0)
            ->call('back')
            ->assertSet('step', 2);
    }

    public function test_previous_from_a_standalone_returns_to_the_search(): void
    {
        // There was no step 2 to go back to.
        $this->fakeMusicBrainz();

        $this->editor()
            ->call('open', 'Spanish', self::FILE)
            ->call('pick', 'c5b644d0-c748-44c0-bebc-0f58140aaa83', 'standalone')
            ->call('back')
            ->assertSet('step', 1);
    }

    public function test_user_edits_override_the_resolved_values(): void
    {
        $this->fakeMusicBrainz();

        $component = $this->editor()
            ->call('open', 'Spanish', self::FILE)
            ->call('pick', '52fa0b53-4bad-4bbe-b23b-d82233500fc7', 'single')
            ->call('pickTrack', 0, 0)
            ->set('overrides.title', 'Creep');

        $this->assertSame('Creep', $component->instance()->resolved->title);
        // Untouched fields keep the MusicBrainz value.
        $this->assertSame('Parlophone', $component->instance()->resolved->label);
    }

    public function test_a_blank_override_falls_back_rather_than_erasing(): void
    {
        // Clearing an input must not write an empty tag over a good one.
        $this->fakeMusicBrainz();

        $component = $this->editor()
            ->call('open', 'Spanish', self::FILE)
            ->call('pick', '52fa0b53-4bad-4bbe-b23b-d82233500fc7', 'single')
            ->call('pickTrack', 0, 0)
            ->set('overrides.title', '');

        $this->assertSame('Creep (live)', $component->instance()->resolved->title);
    }

    // ------------------------------------------------------------------- busy UI

    // Every step waits on MusicBrainz behind a one-request-per-second throttle, so a click
    // can sit for seconds with nothing on screen changing.

    public function test_the_search_button_reports_that_it_is_working(): void
    {
        $html = $this->editor()->call('open', 'Spanish', self::FILE)->html();

        $this->assertStringContainsString('wire:target="search"', $html);
        $this->assertStringContainsString('Searching…', $html);
    }

    public function test_picking_a_release_reports_that_it_is_working(): void
    {
        $this->fakeMusicBrainz();

        $html = $this->editor()
            ->call('open', 'Spanish', self::FILE)
            ->call('search')
            ->html();

        $this->assertStringContainsString('Loading release from MusicBrainz…', $html);
        $this->assertStringContainsString('wire:target="pick"', $html);

        /*
         * .flex, not a bare wire:loading. Livewire writes the display value inline when it
         * shows an element and its default is inline-block, which silently beats a `flex`
         * class and drops the label onto its own line under the spinner.
         */
        $this->assertStringContainsString('wire:loading.flex', $html);

        // The table stops taking clicks, so an impatient second click cannot queue a second
        // lookup and make the wait longer still.
        $this->assertStringContainsString('wire:loading.class="pointer-events-none"', $html);
    }

    public function test_picking_a_track_reports_that_it_is_working(): void
    {
        $this->fakeMusicBrainz();

        $html = $this->editor()
            ->call('open', 'Spanish', self::FILE)
            ->call('pick', '52fa0b53-4bad-4bbe-b23b-d82233500fc7', 'single')
            ->assertSet('step', 2)
            ->html();

        $this->assertStringContainsString('Loading track metadata…', $html);
        $this->assertStringContainsString('wire:target="pickTrack"', $html);
    }

    public function test_writing_reports_that_it_is_working(): void
    {
        $html = $this->atStepThree()->html();

        // The longest wait in the modal - a cover-art download plus a metaflac call - and
        // the one where a double click would do real damage.
        $this->assertStringContainsString('Writing tags and embedding cover art…', $html);
        $this->assertStringContainsString('Writing…', $html);
        $this->assertStringContainsString('wire:target="write"', $html);
    }

    // -------------------------------------------------------------------- genre

    private function atStepThree()
    {
        $this->fakeMusicBrainz();

        return $this->editor()
            ->call('open', 'Spanish', self::FILE)
            ->call('pick', '52fa0b53-4bad-4bbe-b23b-d82233500fc7', 'single')
            ->call('pickTrack', 0, 0);
    }

    public function test_the_genre_can_be_overridden(): void
    {
        /*
         * Editable because MusicBrainz frequently has no genre at all, or has one nobody
         * would call the record by - the one descriptive field where the database is
         * routinely worse than the person looking at the file.
         */
        $component = $this->atStepThree()->set('overrides.genre', 'Trip Hop');

        $this->assertSame('Trip Hop', $component->instance()->resolved->genre());
    }

    public function test_a_genre_override_replaces_what_musicbrainz_suggested(): void
    {
        $component = $this->atStepThree()->set('overrides.genre', 'Trip Hop');

        // Only genres[0] is ever written to the file, so keeping MusicBrainz's other
        // suggestions behind the chosen value would be storing something nothing can reach.
        $this->assertSame(['Trip Hop'], $component->instance()->resolved->genres);
    }

    public function test_the_genre_field_arrives_pre_filled(): void
    {
        $component = $this->atStepThree();

        /*
         * Unlike the other four overrides, which are blank behind a placeholder. Genre is a
         * list assembled from suggestion pills, so the field has to show its real contents -
         * see the next test for what that buys.
         */
        $this->assertNotSame('', $component->get('overrides.genre'));
        $this->assertSame(
            $component->instance()->resolved->genreList(),
            $component->get('overrides.genre'),
        );
    }

    public function test_clearing_the_genre_field_means_none_rather_than_falling_back(): void
    {
        $component = $this->atStepThree()->set('overrides.genre', '');

        // The opposite of title/artist/album/year, which are single values shown as a
        // placeholder where blank means "keep what MusicBrainz said". Genre's field holds its
        // actual contents, so blank means the user emptied it.
        $this->assertSame([], $component->instance()->resolved->genres);
        $this->assertNull($component->instance()->resolved->genre());
    }

    public function test_several_genres_can_be_written_at_once(): void
    {
        /*
         * A COMMA separates, not a space. Genres routinely contain a space - "alternative
         * pop", "drum and bass" - so splitting on whitespace would shred almost every real
         * value into fragments.
         */
        $component = $this->atStepThree()->set('overrides.genre', 'Trip Hop, Downtempo');

        $this->assertSame(['Trip Hop', 'Downtempo'], $component->instance()->resolved->genres);
    }

    public function test_a_semicolon_also_separates(): void
    {
        // Several other taggers join a list with "; ", so a value pasted from one of them
        // should do the obvious thing rather than becoming one absurd genre.
        $component = $this->atStepThree()->set('overrides.genre', 'Trip Hop; Downtempo');

        $this->assertSame(['Trip Hop', 'Downtempo'], $component->instance()->resolved->genres);
    }

    public function test_a_multi_word_genre_survives_intact(): void
    {
        $component = $this->atStepThree()->set('overrides.genre', 'alternative pop');

        $this->assertSame(['alternative pop'], $component->instance()->resolved->genres);
    }

    public function test_repeated_genres_are_dropped(): void
    {
        // Writing GENRE twice with the same word is legal and useless. The first spelling
        // wins so the user's capitalisation is kept.
        $component = $this->atStepThree()->set('overrides.genre', 'Pop, pop , POP');

        $this->assertSame(['Pop'], $component->instance()->resolved->genres);
    }

    public function test_a_suggestion_toggles_into_and_out_of_the_field(): void
    {
        $component = $this->atStepThree();

        $suggestions = $component->instance()->suggestedGenres;
        $this->assertNotEmpty($suggestions, 'the captured release should carry genres');

        // Starts selected, because the field falls back to what MusicBrainz suggested.
        $this->assertTrue($component->instance()->genreSelected($suggestions[0]));

        $component->call('toggleGenre', $suggestions[0]);
        $this->assertFalse($component->instance()->genreSelected($suggestions[0]));

        $component->call('toggleGenre', $suggestions[0]);
        $this->assertTrue($component->instance()->genreSelected($suggestions[0]));
    }

    public function test_removing_every_suggestion_does_not_silently_restore_them(): void
    {
        $component = $this->atStepThree();

        foreach ($component->instance()->suggestedGenres as $genre) {
            $component->call('toggleGenre', $genre);
        }

        /*
         * An empty result is stored as an empty string, not unset. Unsetting would fall back
         * to what MusicBrainz suggested, so removing the last genre would put them all back -
         * the opposite of what the click asked for.
         */
        $this->assertSame([], $component->instance()->resolved->genres);
    }

    public function test_the_suggestion_list_survives_being_used(): void
    {
        $component = $this->atStepThree();

        $before = $component->instance()->suggestedGenres;
        $this->assertNotEmpty($before);

        $component->call('toggleGenre', $before[0]);

        // Read from $metadata, not the resolved object. An override replaces the resolved list,
        // so reading suggestions off it would collapse them on the first click.
        $this->assertSame($before, $component->instance()->suggestedGenres);
    }

    public function test_a_genre_that_was_never_suggested_is_refused(): void
    {
        $component = $this->atStepThree();

        $before = $component->instance()->resolved->genres;

        // The value arrives from the browser. A click on a suggestion must not be able to
        // write an arbitrary string into a file's tags. Typing one is a different path.
        $component->call('toggleGenre', 'Not A Suggestion');

        $this->assertSame($before, $component->instance()->resolved->genres);
    }

    public function test_the_read_only_grid_no_longer_repeats_the_genre(): void
    {
        $component = $this->atStepThree();

        // It has its own input now; showing it twice invites editing the wrong one.
        $this->assertArrayNotHasKey('GENRE', $component->instance()->resolved->displayFields());
    }

    // -------------------------------------------------------------------- write

    public function test_writing_delegates_to_the_writer_and_tells_the_files_screen(): void
    {
        $this->fakeMusicBrainz();

        $this->mock(MetadataWriter::class)
            ->shouldReceive('write')
            ->once()
            ->andReturn(new MetadataWriteResult(
                new LibraryFile(new LibraryFolder('Spanish'), self::FILE),
            ));

        $this->editor()
            ->call('open', 'Spanish', self::FILE)
            ->call('pick', '52fa0b53-4bad-4bbe-b23b-d82233500fc7', 'single')
            ->call('pickTrack', 0, 0)
            ->call('write')
            // The Files screen owns the listing and both the size and possibly the name
            // just changed.
            ->assertDispatched('library-updated');
    }

    public function test_the_rename_checkbox_is_passed_through(): void
    {
        $this->fakeMusicBrainz();

        $this->mock(MetadataWriter::class)
            ->shouldReceive('write')
            ->once()
            ->withArgs(fn ($file, $metadata, $rename): bool => $rename === true)
            ->andReturn(new MetadataWriteResult(
                new LibraryFile(new LibraryFolder('Spanish'), self::FILE),
            ));

        $this->editor()
            ->call('open', 'Spanish', self::FILE)
            ->set('rename', true)
            ->call('pick', '52fa0b53-4bad-4bbe-b23b-d82233500fc7', 'single')
            ->call('pickTrack', 0, 0)
            ->call('write');
    }

    public function test_writing_without_the_edit_permission_is_refused(): void
    {
        $this->fakeMusicBrainz();

        $user = User::factory()->admin()->create();

        $component = $this->editor($user)
            ->call('open', 'Spanish', self::FILE)
            ->call('pick', '52fa0b53-4bad-4bbe-b23b-d82233500fc7', 'single')
            ->call('pickTrack', 0, 0);

        // Revoked between opening the modal and pressing the button, which is the case a
        // render-time check alone would miss.
        $user->forceFill([Permission::Edit->column() => false])->save();

        $component->call('write')->assertForbidden();
    }
}
