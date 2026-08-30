<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PageHeading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The shell: where the front door leads, and the per-route heading map. */
class ShellNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('music');
    }

    // ------------------------------------------------------------------ front door

    #[Test]
    public function a_guest_at_the_root_is_sent_to_the_login_screen(): void
    {
        // Minizo has no public landing page: the front door is the login form.
        $this->get(route('home'))->assertRedirect(route('login', absolute: false));
    }

    #[Test]
    public function a_signed_in_user_at_the_root_lands_on_download(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertRedirect(route('download', absolute: false));
    }

    #[Test]
    public function the_authenticated_screens_need_a_session(): void
    {
        foreach (['download', 'feed', 'settings.edit'] as $name) {
            $this->get(route($name))->assertRedirect(route('login'));
        }
    }

    // ---------------------------------------------------------------- heading map

    #[Test]
    public function every_screen_has_a_heading_and_subheading(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        // The map is keyed by route name, and route names contain dots. Read with
        // config('minizo.pages.'.$name) the dot is nesting, so `settings.edit` looked up
        // pages['settings']['edit'], missed, and the screen rendered with no heading.
        $expected = [
            'download' => 'Download',
            'feed' => 'Feed',
            'shares' => 'Share links',
            'users' => 'Users',
            'settings.edit' => 'Settings',
        ];

        foreach ($expected as $name => $heading) {
            $this->get(route($name));

            [$actual, $sub] = PageHeading::current();

            $this->assertSame($heading, $actual, "heading for route [{$name}]");
            $this->assertNotEmpty($sub, "subheading for route [{$name}]");
        }
    }

    #[Test]
    public function the_files_screen_heading_is_the_folder_name(): void
    {
        Storage::disk('music')->makeDirectory('Spanish');

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('files', 'Spanish'))
            ->assertOk();

        // A {param} placeholder filled from the route, which is what lets the Files screen
        // carry a dynamic title with no page-side code.
        $this->assertSame('Spanish', PageHeading::heading());
    }

    #[Test]
    public function the_heading_appears_in_the_page_and_the_browser_title(): void
    {
        // The <title> element is pretty-printed across three lines in partials.head, so the
        // text is asserted rather than the whole tag.
        $this->actingAs(User::factory()->create())
            ->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('Settings - '.config('app.name'), escape: false)
            ->assertSee('Profile, security &amp; 2FA', escape: false);
    }

    #[Test]
    public function an_unmapped_route_yields_no_heading_rather_than_a_placeholder(): void
    {
        $this->actingAs(User::factory()->create())->get(route('home'));

        $this->assertNull(PageHeading::heading());
    }
}
