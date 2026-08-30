<?php

namespace Tests\Feature\Metadata;

use App\Enums\ReleaseType;
use App\Services\MusicBrainz\MusicBrainzSearch;
use Illuminate\Support\Facades\Http;
use Tests\Support\ReadsFixtures;
use Tests\TestCase;

/** The four search passes, against JSON captured from the live API. */
class MusicBrainzSearchTest extends TestCase
{
    use ReadsFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        // The client's throttle is a real sleep; nothing to wait for against a fake.
        config()->set('minizo.musicbrainz.min_request_interval', 0);
    }

    private function search(): MusicBrainzSearch
    {
        return app(MusicBrainzSearch::class);
    }

    public function test_the_release_query_uses_recording_and_a_phrase_not_track_and_a_range(): void
    {
        // The most important assertion in this file. The obvious query is
        //
        //     track:Creep AND artist:[Radiohead]
        //
        // which uses a field that does not exist on release search, plus Lucene range syntax
        // where a phrase was meant. Measured live: 583,650 results against 18 for this form.
        Http::fake(['musicbrainz.org/*' => Http::response(['releases' => [], 'recordings' => []])]);

        $this->search()->search('Radiohead', 'Creep');

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/release?')) {
                return false;
            }

            $query = urldecode($request->url());

            return str_contains($query, 'recording:"Creep"')
                && str_contains($query, 'artist:"Radiohead"')
                && ! str_contains($query, 'track:')
                && ! str_contains($query, 'artist:[');
        });
    }

    public function test_it_maps_release_search_results(): void
    {
        Http::fake([
            'musicbrainz.org/ws/2/release?*' => Http::response($this->musicBrainzFixture('release-search')),
            'musicbrainz.org/ws/2/recording?*' => Http::response(['recordings' => []]),
        ]);

        $candidates = $this->search()->search('Radiohead', 'Creep');

        $this->assertNotEmpty($candidates);

        $single = collect($candidates)->firstWhere('id', '52fa0b53-4bad-4bbe-b23b-d82233500fc7');

        $this->assertNotNull($single);
        $this->assertSame('Creep', $single->title);
        $this->assertSame('Radiohead', $single->artist);
        $this->assertSame(ReleaseType::Single, $single->type);
        $this->assertSame('1993', $single->year());
        $this->assertSame('GB', $single->country);
        $this->assertSame('Official', $single->status);
        $this->assertSame(4, $single->trackCount);
    }

    public function test_a_recording_result_expands_into_its_releases_with_no_second_request(): void
    {
        /*
         * This is what makes pass B affordable. Recording results carry a `releases`
         * array already, so 100 recordings expand into their releases for one request
         * - at one request per second, looking each one up would take minutes.
         */
        Http::fake([
            'musicbrainz.org/ws/2/release?*' => Http::response(['releases' => []]),
            'musicbrainz.org/ws/2/recording?*' => Http::response($this->musicBrainzFixture('recording-search')),
        ]);

        $candidates = $this->search()->search('Radiohead', 'Creep');

        $this->assertNotEmpty($candidates);

        // One release call, plus the recording pass and the standalone probe. Nothing
        // per-release.
        Http::assertSentCount(3);
    }

    public function test_the_standalone_probe_uses_the_filter_that_actually_works(): void
    {
        // `-reid:*` is verified against the live API: 58 hits for Radiohead, every one with
        // zero releases. `tracks:0` is the obvious alternative and returns nothing at all.
        Http::fake(['musicbrainz.org/*' => Http::response(['releases' => [], 'recordings' => []])]);

        $this->search()->search('Radiohead', 'Creep');

        Http::assertSent(function ($request): bool {
            $query = urldecode($request->url());

            return str_contains($request->url(), '/recording?')
                && str_contains($query, '-reid:*');
        });
    }

    public function test_a_recording_with_no_releases_becomes_a_standalone_candidate(): void
    {
        Http::fake([
            'musicbrainz.org/ws/2/release?*' => Http::response(['releases' => []]),
            'musicbrainz.org/ws/2/recording?*' => Http::response($this->musicBrainzFixture('recording-standalone')),
        ]);

        $candidates = $this->search()->search('Radiohead', 'Creep');

        $this->assertNotEmpty($candidates);

        foreach ($candidates as $candidate) {
            $this->assertTrue($candidate->isStandalone());
            // Its id is a RECORDING mbid, which is what makes step 2 skippable.
            $this->assertNotSame('', $candidate->id);
        }

        $remix = collect($candidates)->firstWhere('title', 'Nude (Amplive remix)');

        $this->assertNotNull($remix);
        $this->assertSame(188000, $remix->lengthMs);
    }

    public function test_the_standalone_probe_is_skipped_without_an_artist(): void
    {
        // Otherwise the query degenerates to "every recording with no release", which is
        // millions of rows of noise.
        Http::fake(['musicbrainz.org/*' => Http::response(['releases' => [], 'recordings' => []])]);

        $this->search()->search('', 'Creep');

        Http::assertNotSent(fn ($request): bool => str_contains(urldecode($request->url()), '-reid:*'));
    }

    public function test_dismax_runs_only_when_everything_else_came_back_empty(): void
    {
        // dismax escapes special characters server-side but disables field scoping, so it
        // cannot be combined with the structured passes. Raw input like
        // "Radiohead - Creep (Acoustic) [Live]" returns 4,029,774 results without it.
        Http::fake(['musicbrainz.org/*' => Http::response(['releases' => [], 'recordings' => []])]);

        $this->search()->search('Radiohead', 'Creep');

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'dismax=true'));
    }

    public function test_dismax_does_not_run_when_a_structured_pass_found_something(): void
    {
        Http::fake([
            'musicbrainz.org/ws/2/release?*' => Http::response($this->musicBrainzFixture('release-search')),
            'musicbrainz.org/ws/2/recording?*' => Http::response(['recordings' => []]),
        ]);

        $this->search()->search('Radiohead', 'Creep');

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'dismax=true'));
    }

    public function test_the_same_release_found_by_two_passes_appears_once(): void
    {
        // The passes overlap by design; a release listed twice reads as a bug.
        Http::fake([
            'musicbrainz.org/ws/2/release?*' => Http::response($this->musicBrainzFixture('release-search')),
            'musicbrainz.org/ws/2/recording?*' => Http::response($this->musicBrainzFixture('recording-search')),
        ]);

        $candidates = $this->search()->search('Radiohead', 'Creep');

        $ids = array_map(fn ($candidate): string => $candidate->key(), $candidates);

        $this->assertSame(array_values(array_unique($ids)), $ids);
    }

    public function test_results_are_capped(): void
    {
        config()->set('minizo.musicbrainz.max_candidates', 3);

        Http::fake([
            'musicbrainz.org/ws/2/release?*' => Http::response($this->musicBrainzFixture('release-search')),
            'musicbrainz.org/ws/2/recording?*' => Http::response($this->musicBrainzFixture('recording-search')),
        ]);

        $this->assertCount(3, $this->search()->search('Radiohead', 'Creep'));
    }

    public function test_a_failed_request_degrades_instead_of_throwing(): void
    {
        /*
         * Legacy defect: the MusicBrainz helper threw from its CONSTRUCTOR when the
         * token was missing, and the controller built it eagerly - so every /lib/*
         * page returned a 500, including plain browsing that never touched MusicBrainz.
         */
        Http::fake(['musicbrainz.org/*' => Http::response('', 503)]);

        $this->assertSame([], $this->search()->search('Radiohead', 'Creep'));
    }

    public function test_it_sends_a_user_agent_on_every_request(): void
    {
        // A request without one gets a 503 that looks exactly like rate limiting -
        // verified against the live API.
        Http::fake(['musicbrainz.org/*' => Http::response(['releases' => []])]);

        $this->search()->search('Radiohead', 'Creep');

        Http::assertSent(fn ($request): bool => filled($request->header('User-Agent')[0] ?? null)
            && str_contains($request->header('User-Agent')[0], 'Minizo'));
    }

    public function test_an_identical_query_is_served_from_cache(): void
    {
        Http::fake(['musicbrainz.org/*' => Http::response(['releases' => [], 'recordings' => []])]);

        $this->search()->search('Radiohead', 'Creep');
        $countAfterFirst = count(Http::recorded());

        $this->search()->search('Radiohead', 'Creep');

        // MusicBrainz data barely changes, and at one request per second a repeated
        // search must not spend the budget again.
        $this->assertCount($countAfterFirst, Http::recorded());
    }
}
