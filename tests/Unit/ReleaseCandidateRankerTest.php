<?php

namespace Tests\Unit;

use App\Enums\ReleaseType;
use App\Services\MusicBrainz\ReleaseCandidateRanker;
use App\Support\ReleaseCandidate;
use PHPUnit\Framework\TestCase;

/** The ordering of step 1. */
class ReleaseCandidateRankerTest extends TestCase
{
    private function candidate(array $overrides = []): ReleaseCandidate
    {
        return ReleaseCandidate::fromArray([
            'id' => $overrides['id'] ?? bin2hex(random_bytes(8)),
            'title' => 'Creep',
            'artist' => 'Radiohead',
            'type' => ReleaseType::Album->value,
            'status' => 'Official',
            'country' => 'GB',
            'trackCount' => 12,
            'score' => 100,
            ...$overrides,
        ]);
    }

    private function ranker(): ReleaseCandidateRanker
    {
        return new ReleaseCandidateRanker;
    }

    public function test_official_releases_come_first(): void
    {
        // A bootleg or promo has worse data and usually no cover art, whatever its
        // relevance score says.
        $bootleg = $this->candidate(['id' => 'b', 'status' => 'Bootleg', 'score' => 100]);
        $official = $this->candidate(['id' => 'o', 'status' => 'Official', 'score' => 40]);

        $ranked = $this->ranker()->rank([$bootleg, $official]);

        $this->assertSame('o', $ranked[0]->id);
    }

    public function test_a_single_outranks_an_album_which_outranks_a_compilation(): void
    {
        /*
         * The criterion that does the most work. A single is almost always the canonical
         * release for one track; a compilation is almost always the worst, because its
         * track numbering and album title describe a box set rather than the song.
         */
        $ranked = $this->ranker()->rank([
            $this->candidate(['id' => 'comp', 'type' => ReleaseType::Compilation->value]),
            $this->candidate(['id' => 'album', 'type' => ReleaseType::Album->value]),
            $this->candidate(['id' => 'single', 'type' => ReleaseType::Single->value]),
            $this->candidate(['id' => 'ep', 'type' => ReleaseType::Ep->value]),
        ]);

        $this->assertSame(['single', 'ep', 'album', 'comp'], array_map(fn ($c): string => $c->id, $ranked));
    }

    public function test_type_beats_the_musicbrainz_score(): void
    {
        // Which is the point: sorting by score alone is the obvious approach and it puts
        // greatest-hits compilations at the top.
        $ranked = $this->ranker()->rank([
            $this->candidate(['id' => 'comp', 'type' => ReleaseType::Compilation->value, 'score' => 100]),
            $this->candidate(['id' => 'single', 'type' => ReleaseType::Single->value, 'score' => 60]),
        ]);

        $this->assertSame('single', $ranked[0]->id);
    }

    public function test_between_two_of_a_kind_the_shorter_release_wins(): void
    {
        // The shorter is the more specific: a 2-track single tags a track better than a
        // 40-track anthology.
        $ranked = $this->ranker()->rank([
            $this->candidate(['id' => 'long', 'trackCount' => 40]),
            $this->candidate(['id' => 'short', 'trackCount' => 2]),
        ]);

        $this->assertSame('short', $ranked[0]->id);
    }

    public function test_a_worldwide_or_major_country_release_breaks_a_remaining_tie(): void
    {
        // Not a value judgement - those releases simply carry the fullest data, cover
        // art included.
        $ranked = $this->ranker()->rank([
            $this->candidate(['id' => 'jp', 'country' => 'JP']),
            $this->candidate(['id' => 'xw', 'country' => 'XW']),
        ]);

        $this->assertSame('xw', $ranked[0]->id);
    }

    public function test_a_standalone_is_not_penalised_for_having_no_release_status(): void
    {
        /*
         * It has no status because it has no release. Treating that as "not Official"
         * would bury the only candidate that can be right when a track genuinely belongs
         * to no release.
         */
        $ranked = $this->ranker()->rank([
            $this->candidate(['id' => 'promo', 'status' => 'Promotion', 'type' => ReleaseType::Album->value]),
            $this->candidate(['id' => 'solo', 'status' => null, 'type' => ReleaseType::Standalone->value]),
        ]);

        $this->assertSame('solo', $ranked[0]->id);
    }

    public function test_a_standalone_still_ranks_below_a_real_official_release(): void
    {
        // It is the fallback, not the preference.
        $ranked = $this->ranker()->rank([
            $this->candidate(['id' => 'solo', 'status' => null, 'type' => ReleaseType::Standalone->value]),
            $this->candidate(['id' => 'single', 'type' => ReleaseType::Single->value]),
        ]);

        $this->assertSame('single', $ranked[0]->id);
    }

    public function test_the_order_is_reproducible_for_identical_candidates(): void
    {
        // usort is not stable, and an order that shuffles between renders would move the
        // rows under the user's cursor.
        $candidates = [
            $this->candidate(['id' => 'a']),
            $this->candidate(['id' => 'b']),
            $this->candidate(['id' => 'c']),
        ];

        $first = array_map(fn ($c): string => $c->id, $this->ranker()->rank($candidates));

        foreach (range(1, 5) as $ignored) {
            $this->assertSame($first, array_map(fn ($c): string => $c->id, $this->ranker()->rank($candidates)));
        }
    }

    public function test_duplicates_are_dropped_keeping_the_first(): void
    {
        // The passes overlap by design - a release search and a recording search that
        // expands into its releases both surface the same release.
        $deduped = $this->ranker()->dedupe([
            $this->candidate(['id' => 'x', 'score' => 90]),
            $this->candidate(['id' => 'x', 'score' => 10]),
            $this->candidate(['id' => 'y']),
        ]);

        $this->assertCount(2, $deduped);
        $this->assertSame(90, $deduped[0]->score);
    }

    public function test_a_release_and_a_recording_sharing_an_id_are_not_confused(): void
    {
        // MBIDs are unique per entity type, not globally, so the dedup key has to name
        // the type.
        $deduped = $this->ranker()->dedupe([
            $this->candidate(['id' => 'same', 'type' => ReleaseType::Single->value]),
            $this->candidate(['id' => 'same', 'type' => ReleaseType::Standalone->value]),
        ]);

        $this->assertCount(2, $deduped);
    }
}
