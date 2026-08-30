<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\User;
use App\Support\PaletteDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** The Ctrl+K palette: go anywhere, or find a track in any folder. */
class PaletteTest extends TestCase
{
    use RefreshDatabase;

    private function library(): void
    {
        $disk = Storage::fake('music');

        $disk->makeDirectory('Spanish');
        $disk->makeDirectory('Folk');
        $disk->makeDirectory('Hidden');

        $disk->put('Spanish/Emilia - Perreito salvaje.flac', 'audio');
        $disk->put('Spanish/Anitta - Você Ja Sabe.flac', 'audio');
        $disk->put('Folk/Bon Iver - Holocene.flac', 'audio');
        $disk->put('Hidden/Secret - Track.flac', 'audio');
    }

    private function labels(array $results, ?string $kind = null): array
    {
        return array_values(array_map(
            fn (array $result): string => $result['label'],
            $kind === null
                ? $results
                : array_filter($results, fn (array $result): bool => $result['kind'] === $kind),
        ));
    }

    // ------------------------------------------------------------------ scoping

    public function test_a_track_in_a_folder_the_user_cannot_see_is_never_offered(): void
    {
        // The palette searches every folder, so it is the one place where forgetting
        // folder_access would quietly hand out the whole library.
        $this->library();

        $results = Livewire::actingAs(User::factory()->withFolders(['Spanish'])->create())
            ->test('pages::palette')
            ->set('query', 'track')
            ->instance()
            ->results;

        $this->assertSame([], $this->labels($results, 'song'));
    }

    public function test_a_user_with_access_finds_tracks_in_every_folder_they_can_see(): void
    {
        $this->library();

        $results = Livewire::actingAs(User::factory()->withFolders(['Spanish', 'Folk'])->create())
            ->test('pages::palette')
            ->set('query', 'o')
            ->set('query', 'ho')
            ->instance()
            ->results;

        $this->assertContains('Bon Iver - Holocene', $this->labels($results, 'song'));
    }

    // ------------------------------------------------------------- destinations

    public function test_the_destination_list_follows_the_same_gates_as_the_sidebar(): void
    {
        // A palette that offers a screen you would be 403'd on is worse than one that
        // does not offer it at all.
        $admin = PaletteDestination::forUser(User::factory()->admin()->create());
        $plain = PaletteDestination::forUser(
            User::factory()->without([Permission::Share])->create()
        );

        $names = fn (array $set): array => array_map(fn ($d): string => $d->label, $set);

        $this->assertContains('Users', $names($admin));
        $this->assertContains('Share links', $names($admin));

        $this->assertNotContains('Users', $names($plain));
        $this->assertNotContains('Share links', $names($plain));
    }

    public function test_destinations_match_on_keywords_not_only_the_label(): void
    {
        // "2fa" is not on any label, but it is what someone types to reach Settings.
        $destinations = PaletteDestination::forUser(User::factory()->create());

        $settings = collect($destinations)->firstWhere('label', 'Settings');

        $this->assertTrue($settings->matches('2fa'));
        $this->assertTrue($settings->matches('passkey'));
        $this->assertFalse($settings->matches('nonsense'));
    }

    // ------------------------------------------------------------------ matching

    public function test_the_extension_is_not_searchable(): void
    {
        /*
         * The Files screen's own filter matches the full filename, which is right for a
         * per-folder box. Here it would mean typing "flac" returns the entire library,
         * so the palette matches basename() instead.
         */
        $this->library();

        $results = Livewire::actingAs(User::factory()->create())
            ->test('pages::palette')
            ->set('query', 'flac')
            ->instance()
            ->results;

        $this->assertSame([], $this->labels($results, 'song'));
    }

    public function test_one_character_does_not_search_the_library(): void
    {
        // Across every folder, a single character is noise rather than a search.
        $this->library();

        $component = Livewire::actingAs(User::factory()->create())->test('pages::palette');

        $this->assertSame([], $this->labels($component->set('query', 'e')->instance()->results, 'song'));
        $this->assertNotSame([], $this->labels($component->set('query', 'em')->instance()->results, 'song'));
    }

    public function test_an_earlier_match_ranks_above_a_later_one(): void
    {
        // "emi" should put "Emilia …" above a track that merely contains it later on.
        $disk = Storage::fake('music');
        $disk->makeDirectory('Spanish');
        $disk->put('Spanish/Emilia - Salvaje.flac', 'audio');
        $disk->put('Spanish/Bad Bunny, Emilia - Otro.flac', 'audio');

        $results = Livewire::actingAs(User::factory()->create())
            ->test('pages::palette')
            ->set('query', 'emi')
            ->instance()
            ->results;

        $songs = $this->labels($results, 'song');

        $this->assertSame('Emilia - Salvaje', $songs[0]);
    }

    // -------------------------------------------------------------- navigation

    public function test_a_song_links_to_its_own_folder_with_the_filter_applied(): void
    {
        // The deep link reuses the Files screen's #[Url(as: 'q')] filter, so the row
        // arrives on screen with its row menu rather than merely in the right folder.
        $this->library();

        $results = Livewire::actingAs(User::factory()->create())
            ->test('pages::palette')
            ->set('query', 'holocene')
            ->instance()
            ->results;

        $song = collect($results)->firstWhere('kind', 'song');

        $this->assertStringContainsString('/files/Folk', $song['url']);
        $this->assertStringContainsString('q=', $song['url']);
    }

    public function test_enter_redirects_to_the_highlighted_result(): void
    {
        $this->library();

        Livewire::actingAs(User::factory()->create())
            ->test('pages::palette')
            ->set('query', 'holocene')
            ->call('go', 0)
            ->assertRedirect();
    }

    public function test_an_out_of_range_index_is_ignored_rather_than_fatal(): void
    {
        // The highlight is Alpine state, so the index arrives from the client.
        $this->library();

        Livewire::actingAs(User::factory()->create())
            ->test('pages::palette')
            ->call('go', 9999)
            ->assertNoRedirect();
    }

    public function test_folders_are_offered_too(): void
    {
        $this->library();

        $results = Livewire::actingAs(User::factory()->withFolders(['Spanish'])->create())
            ->test('pages::palette')
            ->set('query', 'span')
            ->instance()
            ->results;

        $this->assertSame(['Spanish'], $this->labels($results, 'folder'));
    }

    public function test_an_empty_query_still_offers_somewhere_to_go(): void
    {
        // Ctrl+K then Enter should do something useful rather than nothing.
        $this->library();

        $results = Livewire::actingAs(User::factory()->create())
            ->test('pages::palette')
            ->instance()
            ->results;

        $this->assertNotSame([], $this->labels($results, 'destination'));
        $this->assertSame([], $this->labels($results, 'song'));
    }
}
