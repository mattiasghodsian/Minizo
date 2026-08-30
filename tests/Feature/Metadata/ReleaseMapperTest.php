<?php

namespace Tests\Feature\Metadata;

use App\Services\MusicBrainz\RecordingMapper;
use App\Services\MusicBrainz\ReleaseMapper;
use Tests\Support\ReadsFixtures;
use Tests\TestCase;

/** The mapper that replaces MetaDataService, against a real release document. */
class ReleaseMapperTest extends TestCase
{
    use ReadsFixtures;

    /**
     * @return array<string, mixed>
     */
    private function release(): array
    {
        return $this->musicBrainzFixture('release-lookup');
    }

    private function mapper(): ReleaseMapper
    {
        return app(ReleaseMapper::class);
    }

    // ------------------------------------------------------------- step 2: tracks

    public function test_it_flattens_every_track_across_discs(): void
    {
        $tracks = $this->mapper()->tracks($this->release());

        // The fixture is the Creep 12" single: one medium, four tracks.
        $this->assertCount(4, $tracks);
        $this->assertSame('A1', $tracks[0]->number);
        $this->assertSame('Creep (live)', $tracks[0]->title);
        $this->assertSame(0, $tracks[0]->mediaPosition);
        $this->assertSame(0, $tracks[0]->trackIndex);
    }

    public function test_a_null_length_is_reported_as_a_dash_not_zero(): void
    {
        // MusicBrainz genuinely returns null lengths - every track on this release has
        // one - and "0:00" would read as a broken file rather than missing data.
        $tracks = $this->mapper()->tracks($this->release());

        $this->assertNull($tracks[0]->lengthMs);
        $this->assertSame('—', $tracks[0]->durationLabel());
    }

    public function test_exactly_one_track_is_flagged_as_the_best_match(): void
    {
        $tracks = $this->mapper()->tracks($this->release(), 'Creep');

        $flagged = array_filter($tracks, fn ($track): bool => $track->isBestMatch);

        $this->assertCount(1, $flagged);
    }

    public function test_the_flagged_track_is_the_highest_scoring_one(): void
    {
        // Not the first, and not the one whose title merely starts the same way:
        // "Killer Cars (live)" is track 4.
        $tracks = $this->mapper()->tracks($this->release(), 'Killer Cars');

        $best = collect($tracks)->firstWhere('isBestMatch', true);
        $highest = collect($tracks)->sortByDesc('matchScore')->first();

        $this->assertNotNull($best);
        $this->assertSame('Killer Cars (live)', $best->title);
        $this->assertSame($highest->matchScore, $best->matchScore);
    }

    public function test_a_tie_resolves_to_the_earlier_track_deterministically(): void
    {
        // Arbitrary but stable, which is the property that matters: a coin flip would move the
        // badge between renders of the same release.
        $release = $this->release();
        $release['media'][0]['tracks'][0]['title'] = 'Identical';
        $release['media'][0]['tracks'][1]['title'] = 'Identical';

        foreach (range(1, 3) as $ignored) {
            $tracks = $this->mapper()->tracks($release, 'Identical');

            $this->assertTrue($tracks[0]->isBestMatch);
            $this->assertFalse($tracks[1]->isBestMatch);
        }
    }

    public function test_nothing_is_flagged_when_there_is_no_search_title(): void
    {
        // Every score is zero, so there is no signal to flag on - and a "Best Match"
        // badge on an arbitrary row is worse than none.
        $tracks = $this->mapper()->tracks($this->release());

        $this->assertSame([], array_filter($tracks, fn ($track): bool => $track->isBestMatch));
    }

    public function test_matching_is_insensitive_to_accents_and_case(): void
    {
        /*
         * The genuine improvement over the legacy comparison, which fed raw strings to
         * similar_text() - a byte-based function that scores multi-byte titles oddly.
         * This library is largely Spanish and Portuguese.
         */
        $release = $this->release();
        $release['media'][0]['tracks'][2]['title'] = 'Você Ja Sabe';

        $tracks = $this->mapper()->tracks($release, 'voce ja sabe');

        $this->assertTrue($tracks[2]->isBestMatch);
        $this->assertSame(100.0, $tracks[2]->matchScore);
    }

    // ------------------------------------------------------------ step 3: metadata

    public function test_it_maps_the_full_tag_set_for_a_track(): void
    {
        $metadata = $this->mapper()->metadata($this->release(), 0, 0);

        $this->assertSame('Creep (live)', $metadata->title);
        $this->assertSame('Radiohead', $metadata->artist);
        $this->assertSame('Creep', $metadata->album);
        $this->assertSame('1993', $metadata->year);
        $this->assertSame('A1', $metadata->trackNumber);
        $this->assertSame(4, $metadata->totalTracks);
        $this->assertSame('724388092302', $metadata->barcode);
        $this->assertSame('Parlophone', $metadata->label);
        $this->assertSame('Official', $metadata->status);
        $this->assertSame('12" Vinyl', $metadata->mediaFormat);
        $this->assertSame('GB', $metadata->country);
        $this->assertSame('eng', $metadata->language);
        $this->assertSame('52fa0b53-4bad-4bbe-b23b-d82233500fc7', $metadata->releaseId);
        $this->assertSame('402b2ed6-d942-4d43-8ad4-f9ca9cc9db68', $metadata->recordingId);
        $this->assertFalse($metadata->standalone);
    }

    public function test_only_the_year_is_taken_from_a_musicbrainz_date(): void
    {
        // Dates come as a year, a year-month, or a full date; a tag holds a year.
        $this->assertSame('1993', $this->mapper()->metadata($this->release(), 0, 0)->year);
    }

    public function test_the_external_link_is_read_from_the_url_relations(): void
    {
        // Read through `relations.*.url.resource`, a wildcard path, so this pins the
        // traversal as much as the value.
        $this->assertSame(
            'https://www.discogs.com/release/458914',
            $this->mapper()->metadata($this->release(), 0, 0)->link,
        );
    }

    public function test_the_recordings_genres_win_over_the_releases(): void
    {
        /*
         * The release's own `genres` is an empty array on this fixture while the
         * recording and the label both have entries. The legacy table read the release
         * only, so genre came out blank for exactly this shape.
         */
        $release = $this->release();
        $release['media'][0]['tracks'][0]['recording']['genres'] = [
            ['name' => 'alternative rock', 'count' => 4],
        ];

        $this->assertSame('alternative rock', $this->mapper()->metadata($release, 0, 0)->genre());
    }

    public function test_the_tracks_artist_credit_beats_the_releases(): void
    {
        /*
         * They differ exactly where it matters most: on a compilation the release is
         * credited to "Various Artists" and only the track knows who performed it.
         */
        $release = $this->release();
        $release['artist-credit'] = [['name' => 'Various Artists']];
        $release['media'][0]['tracks'][0]['artist-credit'] = [['name' => 'Radiohead']];

        $metadata = $this->mapper()->metadata($release, 0, 0);

        $this->assertSame('Radiohead', $metadata->artist);
        // The album artist is still the release's, which is what a player groups by.
        $this->assertSame('Various Artists', $metadata->albumArtist);
    }

    public function test_a_joinphrase_is_used_verbatim_when_flattening_a_credit(): void
    {
        // The joinphrase carries its own spacing; inserting a separator doubles it.
        $release = $this->release();
        $release['media'][0]['tracks'][0]['artist-credit'] = [
            ['name' => 'Emilia', 'joinphrase' => ' feat. '],
            ['name' => 'Nicki Nicole', 'joinphrase' => ''],
        ];

        $this->assertSame('Emilia feat. Nicki Nicole', $this->mapper()->metadata($release, 0, 0)->artist);
    }

    public function test_an_out_of_range_track_yields_nulls_rather_than_an_error(): void
    {
        // The indices arrive from the client, so they cannot be trusted to exist.
        $metadata = $this->mapper()->metadata($this->release(), 9, 99);

        $this->assertNull($metadata->title);
        $this->assertFalse($metadata->isWritable());
    }

    // ---------------------------------------------------------------- standalone

    public function test_a_standalone_recording_maps_to_a_sparse_tag_set(): void
    {
        /*
         * The shape the design shows with blank cells. Asserting the nulls explicitly,
         * because the temptation is to fill album with the title - which would make
         * every standalone look like a single-track album in a player's library.
         */
        $recording = [
            'id' => 'c5b644d0-c748-44c0-bebc-0f58140aaa83',
            'title' => 'Nude (Amplive remix)',
            'length' => 188000,
            'first-release-date' => '2008-04-01',
            'artist-credit' => [['name' => 'Radiohead']],
            'isrcs' => ['GBAYE0800280'],
            'genres' => [['name' => 'remix']],
        ];

        $metadata = app(RecordingMapper::class)->metadata($recording);

        $this->assertTrue($metadata->standalone);
        $this->assertSame('Nude (Amplive remix)', $metadata->title);
        $this->assertSame('Radiohead', $metadata->artist);
        $this->assertSame('2008', $metadata->year);
        $this->assertSame(188000, $metadata->lengthMs);
        $this->assertSame('3:08', $metadata->lengthLabel());
        $this->assertSame('GBAYE0800280', $metadata->isrc);
        $this->assertSame('remix', $metadata->genre());
        $this->assertSame('c5b644d0-c748-44c0-bebc-0f58140aaa83', $metadata->recordingId);

        // Everything a release would have provided.
        $this->assertNull($metadata->album);
        $this->assertNull($metadata->releaseId);
        $this->assertNull($metadata->barcode);
        $this->assertNull($metadata->label);
        $this->assertNull($metadata->status);
        $this->assertNull($metadata->trackNumber);
        $this->assertNull($metadata->totalTracks);
        $this->assertNull($metadata->country);
        $this->assertNull($metadata->mediaFormat);

        // And no cover art, because Cover Art Archive is keyed by release.
        $this->assertNull($metadata->coverArtUrl);
    }

    public function test_the_step_three_grid_shows_blanks_for_what_a_standalone_lacks(): void
    {
        $metadata = app(RecordingMapper::class)->metadata([
            'id' => 'abc',
            'title' => 'Winter Wonderland (live from webcast)',
            'artist-credit' => [['name' => 'Radiohead']],
        ]);

        $fields = $metadata->displayFields();

        // The cells are present but empty - the design keeps the grid intact.
        $this->assertArrayHasKey('ALBUM', $fields);
        $this->assertArrayHasKey('BARCODE', $fields);
        $this->assertNull($fields['ALBUM']);
        $this->assertNull($fields['BARCODE']);
        $this->assertSame('Winter Wonderland (live from webcast)', $fields['TITLE']);
    }

    // ------------------------------------------------------------------- genres

    public function test_genres_fall_back_to_the_release_group(): void
    {
        // On the captured release both the recording's and the release's own genres are empty
        // while the release-group carries five, so reading only the release returns nothing for
        // a record that plainly has a genre.
        $release = $this->musicBrainzFixture('release-lookup');

        $this->assertSame([], $release['genres']);
        $this->assertSame([], $release['media'][0]['tracks'][0]['recording']['genres']);
        $this->assertNotEmpty($release['release-group']['genres']);

        $metadata = app(ReleaseMapper::class)->metadata($release, 0, 0);

        $this->assertNotEmpty($metadata->genres);
        $this->assertSame($release['release-group']['genres'][0]['name'], $metadata->genre());
    }

    public function test_a_more_specific_genre_wins(): void
    {
        // The recording describes the song; the release-group describes the album. When both
        // are present the song's own is the better answer.
        $release = [
            'id' => 'r1',
            'genres' => [['name' => 'from the release']],
            'release-group' => ['genres' => [['name' => 'from the group']]],
            'media' => [[
                'format' => 'CD',
                'tracks' => [[
                    'title' => 'A song',
                    'recording' => ['id' => 'rec1', 'genres' => [['name' => 'from the recording']]],
                ]],
            ]],
        ];

        $this->assertSame(
            'from the recording',
            app(ReleaseMapper::class)->metadata($release, 0, 0)->genre(),
        );
    }

    public function test_the_artists_own_genres_are_not_borrowed(): void
    {
        /*
         * An artist's genres describe a body of work, not this track. Falling back to them
         * would confidently tag a quiet piano interlude with whatever the band is generally
         * known for - which is worse than leaving it blank, because it looks correct.
         */
        $release = [
            'id' => 'r1',
            'genres' => [],
            'artist-credit' => [['artist' => ['genres' => [['name' => 'alternative rock']]]]],
            'media' => [[
                'format' => 'CD',
                'tracks' => [['title' => 'A song', 'recording' => ['id' => 'rec1', 'genres' => []]]],
            ]],
        ];

        $this->assertSame([], app(ReleaseMapper::class)->metadata($release, 0, 0)->genres);
    }
}
