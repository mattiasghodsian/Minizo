<?php

namespace Tests\Feature\Library;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class FilesScreenTest extends TestCase
{
    use RefreshDatabase;

    private function library(): void
    {
        $disk = Storage::fake('music');

        $disk->makeDirectory('Spanish');
        $disk->makeDirectory('Folk');

        // Mixed sizes and extensions: sorting and the taggable/listable split both need
        // something to bite on.
        $disk->put('Spanish/beta.flac', str_repeat('x', 3_000_000));
        $disk->put('Spanish/alpha.mp3', str_repeat('x', 1_000_000));
        $disk->put('Spanish/gamma.flac', str_repeat('x', 2_000_000));

        // Not audio - must never appear.
        $disk->put('Spanish/cover.jpg', 'x');

        $disk->put('Folk/other.flac', 'x');
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_it_lists_the_audio_files_in_a_folder(): void
    {
        $this->library();

        Livewire::actingAs($this->admin())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->assertSee('alpha.mp3')
            ->assertSee('beta.flac')
            ->assertSee('gamma.flac')
            // Not audio, and not from another folder.
            ->assertDontSee('cover.jpg')
            ->assertDontSee('other.flac');
    }

    public function test_it_lists_formats_it_cannot_tag(): void
    {
        // The listable/taggable split. Hiding an existing mp3 collection because
        // Minizo only WRITES flac would look like data loss.
        $this->library();

        Livewire::actingAs($this->admin())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->assertSee('alpha.mp3')
            ->assertSee('MP3');
    }

    public function test_sorting_by_size_reorders_the_rows(): void
    {
        $this->library();

        $component = Livewire::actingAs($this->admin())
            ->test('pages::files', ['directory' => 'Spanish']);

        // Ascending: 1MB, 2MB, 3MB -> alpha, gamma, beta
        $component->call('sortBy', 'size')
            ->assertSet('sort', 'size')
            ->assertSet('descending', false)
            ->assertSeeInOrder(['alpha.mp3', 'gamma.flac', 'beta.flac']);

        // Clicking the active column flips direction.
        $component->call('sortBy', 'size')
            ->assertSet('descending', true)
            ->assertSeeInOrder(['beta.flac', 'gamma.flac', 'alpha.mp3']);
    }

    public function test_sorting_by_a_new_column_starts_ascending(): void
    {
        $this->library();

        Livewire::actingAs($this->admin())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->call('sortBy', 'size')
            ->call('sortBy', 'size')
            ->assertSet('descending', true)
            ->call('sortBy', 'name')
            ->assertSet('sort', 'name')
            ->assertSet('descending', false);
    }

    public function test_an_unknown_sort_column_is_ignored(): void
    {
        $this->library();

        Livewire::actingAs($this->admin())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->call('sortBy', 'id; DROP TABLE users')
            ->assertSet('sort', 'name');
    }

    public function test_filtering_narrows_the_list(): void
    {
        $this->library();

        Livewire::actingAs($this->admin())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->set('filter', 'alpha')
            ->assertSee('alpha.mp3')
            ->assertDontSee('beta.flac')
            ->assertDontSee('gamma.flac');
    }

    public function test_filtering_is_case_insensitive(): void
    {
        $this->library();

        Livewire::actingAs($this->admin())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->set('filter', 'ALPHA')
            ->assertSee('alpha.mp3');
    }

    public function test_a_filter_matching_nothing_shows_an_empty_state(): void
    {
        $this->library();

        Livewire::actingAs($this->admin())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->set('filter', 'nothing-matches-this')
            ->assertSee('No files match');
    }

    public function test_an_unknown_folder_is_a_404(): void
    {
        $this->library();

        $this->actingAs($this->admin())
            ->get('/files/DoesNotExist')
            ->assertNotFound();
    }

    public function test_a_folder_the_user_cannot_see_is_refused(): void
    {
        $this->library();

        $user = User::factory()->withFolders(['Folk'])->create();

        $this->actingAs($user)->get('/files/Spanish')->assertForbidden();
        $this->actingAs($user)->get('/files/Folk')->assertOk();
    }

    public function test_the_route_rejects_a_traversal_attempt(): void
    {
        $this->library();

        // The route regex excludes separators, so this never reaches the component.
        $this->actingAs($this->admin())
            ->get('/files/'.urlencode('../../.env'))
            ->assertNotFound();
    }

    public function test_every_row_action_renders_a_compiled_wire_click(): void
    {
        // A regression test only a rendering assertion can catch.
        //
        // `wire:click="openMove(@js($file->filename))"` on x-ui.row-menu.item compiles without
        // complaint but is never expanded: row-menu.item forwards its whole attribute bag into
        // flux:menu.item, and a directive in that position reaches the browser as literal text.
        // The menu item then throws in the console while every server-side test still passes.
        // `{{ Js::from(...) }}` is compiled in every position.
        $this->library();

        $html = Livewire::actingAs($this->admin())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->html();

        // Nothing uncompiled reached the browser.
        $this->assertStringNotContainsString('@js(', $html);

        // And the actions carry real arguments.
        $this->assertStringContainsString("openMove('beta.flac')", $html);
        $this->assertStringContainsString("openDelete('beta.flac')", $html);
        $this->assertStringContainsString("filename: 'beta.flac'", $html);
    }

    public function test_pagination_respects_the_users_page_size(): void
    {
        $disk = Storage::fake('music');
        $disk->makeDirectory('Big');

        foreach (range(1, 12) as $i) {
            $disk->put(sprintf('Big/track-%02d.flac', $i), 'x');
        }

        $user = $this->admin();
        $user->forceFill(['pagination_size' => 10])->save();

        Livewire::actingAs($user)
            ->test('pages::files', ['directory' => 'Big'])
            ->assertSee('track-01.flac')
            ->assertSee('track-10.flac')
            ->assertDontSee('track-11.flac')
            ->assertSee('Showing 1 to 10 of 12 results');
    }

    // ------------------------------------------------------------------ row artwork

    public function test_a_taggable_row_carries_its_cover_as_bled_in_artwork(): void
    {
        $this->library();

        $html = Livewire::actingAs($this->admin())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->html();

        // The cover is a background on the row's left edge rather than a thumbnail in a
        // column of its own, so the URL rides on the artwork element.
        $this->assertStringContainsString('row-artwork', $html);
        $this->assertStringContainsString(
            route('files.cover', ['Spanish', 'beta.flac']),
            $html,
        );
    }

    public function test_the_artwork_image_is_lazy_and_removes_itself_on_a_404(): void
    {
        $this->library();

        $html = Livewire::actingAs($this->admin())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->html();

        // Both attributes matter. A CSS background-image would fetch every row's cover as soon
        // as the page rendered, each parsing a 30-40 MB FLAC, so the artwork is a real <img>
        // that can be lazy. onerror covers a file with no embedded art: the endpoint 404s, the
        // element removes itself, and the gradient behind it remains.
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('onerror="this.remove()"', $html);
    }

    public function test_a_row_for_an_untaggable_file_gets_artwork_with_no_image(): void
    {
        $this->library();

        $html = Livewire::actingAs($this->admin())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->html();

        // An mp3 is listed and moved like anything else, but there is no cover endpoint for
        // it - so it gets the generated gradient and no <img> at all.
        $this->assertStringNotContainsString(
            route('files.cover', ['Spanish', 'alpha.mp3']),
            $html,
        );
    }

    public function test_the_table_clips_so_artwork_needs_no_corner_of_its_own(): void
    {
        $this->library();

        $html = Livewire::actingAs($this->admin())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->html();

        // The artwork bleeds to the row's left edge, which on the last row is the card's
        // rounded corner. The wrapper clips, so that corner comes for free. Clipping is safe
        // despite the row menu, which Flux renders as a `popover` in the browser's top layer.
        $this->assertStringNotContainsString('rounded-bl-2xl', $html);
        $this->assertStringContainsString('overflow-hidden rounded-2xl', $html);
    }

    // ---------------------------------------------------------------------- genre

    public function test_the_table_carries_a_genre_column(): void
    {
        $this->library();

        Livewire::actingAs($this->admin())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->assertSee('Genre');
    }

    public function test_the_genre_and_modified_columns_are_desktop_only(): void
    {
        $this->library();

        $html = Livewire::actingAs($this->admin())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->html();

        // Header, cells and grid template all have to agree: a column that leaves the template
        // but not the DOM shifts every cell after it one place left.
        //
        // max-lg:hidden rather than `hidden lg:flex`: both are plain display utilities, so
        // which wins is decided by Tailwind's internal ordering. A variant always sorts after
        // an unvariated utility.
        //
        // Modified hides below the breakpoint too: at 900px the fixed columns and gaps left
        // the File column 84px, of which the artwork inset claims 80.
        /*
         * Modified hides below the breakpoint as well as Genre, and that is not tidying: at
         * 900px the fixed columns plus gaps left the File column 84px, of which the cover
         * artwork's inset claims 80 - the filename was invisible. Reclaiming Modified's
         * 150px is what gives it room back.
         */
        $this->assertSame(
            8,
            substr_count($html, 'max-lg:hidden'),
            'two columns (Genre, Modified) — each a header plus one cell per row, and the Spanish fixture holds 3 audio files',
        );

        $this->assertStringContainsString('lg:[--cols:1fr_44px_130px_80px_90px_150px_44px]!', $html);
    }

    public function test_a_file_with_no_readable_genre_shows_a_dash(): void
    {
        $this->library();

        // Storage::fake writes byte-strings, so none of these are real FLACs and none has a
        // comment block. The column must degrade to a dash rather than erroring.
        Livewire::actingAs($this->admin())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->assertOk()
            ->assertSee('—', escape: false);
    }

    // -------------------------------------------------------------- musicbrainz

    public function test_the_musicbrainz_state_has_its_own_column(): void
    {
        $this->library();

        $html = Livewire::actingAs($this->admin())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->html();

        /*
         * A column rather than a chip beside the filename: one vertical line of marks is
         * what makes "which of these still needs tagging" scannable, and it keeps a machine
         * detail out of the cell that is meant to read as a name.
         */
        $this->assertStringContainsString('Whether the file carries MusicBrainz ids', $html);

        // Every fixture is an untagged byte-string, so every row shows the absent mark.
        $this->assertSame(3, substr_count($html, 'No MusicBrainz metadata</span>'));
        $this->assertStringContainsString('✗', $html);
        $this->assertStringNotContainsString('✓', $html);
    }

    public function test_the_absent_mark_is_faint_rather_than_an_error(): void
    {
        $this->library();

        $html = Livewire::actingAs($this->admin())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->html();

        // An unidentified file is a to-do, not a fault - a column of red on a fresh library
        // would read as one.
        $this->assertStringContainsString('text-ink-faint', $html);
        $this->assertStringNotContainsString('text-danger text-sm', $html);
    }

    public function test_the_musicbrainz_menu_items_are_inert_without_an_id(): void
    {
        $this->library();

        $html = Livewire::actingAs($this->admin())
            ->test('pages::files', ['directory' => 'Spanish'])
            ->html();

        /*
         * Shown-but-inert rather than hidden. "This track was never identified" is exactly
         * what someone opens the menu to find out, and an absent row answers nothing - so
         * the item stays, dimmed, carrying the reason as its title.
         */
        $this->assertStringContainsString('Open recording on MusicBrainz', $html);
        $this->assertStringContainsString('Open release on MusicBrainz', $html);
        $this->assertStringContainsString('no MusicBrainz recording id yet', $html);

        // Inert, and pointing nowhere.
        $this->assertStringNotContainsString('musicbrainz.org/recording/', $html);
        $this->assertStringNotContainsString('musicbrainz.org/release/', $html);
    }
}
