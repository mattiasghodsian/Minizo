<?php

namespace Tests\Unit;

use App\Enums\ReleaseType;
use App\Services\Tidal\TidalDocument;
use App\Services\Tidal\TidalResourceMapper;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ReadsFixtures;
use Tests\TestCase;

/** The mapper from Tidal's JSON:API resources to Minizo's value objects. */
class TidalResourceMapperTest extends TestCase
{
    use ReadsFixtures;

    private TidalResourceMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new TidalResourceMapper;
    }

    private function document(string $name): TidalDocument
    {
        return TidalDocument::from($this->tidalFixture($name));
    }

    // ------------------------------------------------------------------- artists

    #[Test]
    public function it_maps_artists_out_of_a_search_document(): void
    {
        $artists = $this->mapper->artists($this->document('artist-search'));

        $this->assertCount(20, $artists);
        $this->assertSame('4906194', $artists[0]->providerId);
        $this->assertSame('Anitta', $artists[0]->name);
    }

    #[Test]
    public function it_preserves_the_apis_relevance_ranking(): void
    {
        $artists = $this->mapper->artists($this->document('artist-search'));

        // The exact-match artist first, which is why the mapper follows data.relationships
        // rather than reading `included`. `included` comes back sorted by id, which put
        // "Rebecca" first and Anitta seventh for a search for ANITTA.
        $this->assertSame('Anitta', $artists[0]->name);
        $this->assertSame('Pedro Sampaio', $artists[1]->name);
    }

    #[Test]
    public function it_resolves_an_artists_picture_through_its_artwork_relationship(): void
    {
        $artists = $this->mapper->artists($this->document('artist-search'));

        // An artist resource carries name, popularity, externalLinks and ownerType, and no
        // image. The picture is a separate `artworks` resource reached via profileArt, which
        // only appears when the request asked for ?include=artists.profileArt.
        $this->assertStringStartsWith('https://resources.tidal.com/images/', (string) $artists[0]->imageUrl);
    }

    #[Test]
    public function it_picks_the_square_crop_closest_to_320px(): void
    {
        $artists = $this->mapper->artists($this->document('artist-search'));

        // An artwork offers 80 through 1280 square plus 16:9 crops. 320 is the design's card
        // size; 160 is soft on a 2x display and 1280 ships a quarter-megabyte per row. The
        // landscape crops are excluded because a 1280x720 stretched into a square tile puts
        // the subject off-centre.
        $this->assertStringEndsWith('/320x320.jpg', (string) $artists[0]->imageUrl);
    }

    #[Test]
    public function it_scales_a_fractional_popularity_to_a_percentage(): void
    {
        $artists = $this->mapper->artists($this->document('artist-search'));

        // Tidal reports 0.8138…; a plain (int) cast reads 0, rendering "popularity 0" against
        // a chart-topping artist.
        $this->assertSame(81, $artists[0]->popularity);
        $this->assertGreaterThan(0, $artists[1]->popularity);
    }

    #[Test]
    public function it_accepts_popularity_already_expressed_as_a_percentage(): void
    {
        $document = TidalDocument::from(['data' => [
            ['type' => 'artists', 'id' => '1', 'attributes' => ['name' => 'A', 'popularity' => 87]],
            ['type' => 'artists', 'id' => '2', 'attributes' => ['name' => 'B', 'popularity' => 240]],
            ['type' => 'artists', 'id' => '3', 'attributes' => ['name' => 'C', 'popularity' => 1]],
        ]]);

        $artists = $this->mapper->artists($document);

        $this->assertSame(87, $artists[0]->popularity);
        $this->assertSame(100, $artists[1]->popularity, 'out-of-range values clamp rather than overflow the column');
        $this->assertSame(100, $artists[2]->popularity, 'exactly 1 is read as the top of the fractional scale');
    }

    #[Test]
    public function it_links_to_tidal_and_not_to_whichever_social_network_is_listed_first(): void
    {
        $artists = $this->mapper->artists($this->document('artist-search'));

        // `externalLinks` is a mixed bag: the real response lists facebook.com and twitter.com
        // ahead of Tidal's own for several artists. The right entry is identified by
        // meta.type === 'TIDAL_SHARING'.
        foreach ($artists as $artist) {
            $this->assertStringStartsWith('https://tidal.com/browse/artist/', (string) $artist->link);
        }
    }

    #[Test]
    public function it_falls_back_to_a_constructed_tidal_url(): void
    {
        $document = TidalDocument::from(['data' => [
            ['type' => 'artists', 'id' => '999', 'attributes' => ['name' => 'No links at all']],
        ]]);

        // An id always yields a working browse URL, so a missing externalLinks entry is not a
        // reason to render a card with no link.
        $this->assertSame(
            'https://tidal.com/browse/artist/999',
            $this->mapper->artists($document)[0]->link,
        );
    }

    #[Test]
    public function an_artist_with_no_artwork_include_has_no_image(): void
    {
        // What ?include=artists (without .profileArt) returns. The generated tile is what shows.
        $document = TidalDocument::from(['data' => [
            ['type' => 'artists', 'id' => '1', 'attributes' => ['name' => 'Anitta', 'popularity' => 0.8]],
        ]]);

        $this->assertNull($this->mapper->artists($document)[0]->imageUrl);
    }

    #[Test]
    public function it_drops_an_artist_with_no_name(): void
    {
        // Unfollowable and unshowable, so a half-built object is worse than none.
        $document = TidalDocument::from(['data' => [
            ['type' => 'artists', 'id' => '1', 'attributes' => ['name' => '  ']],
            ['type' => 'artists', 'attributes' => ['name' => 'No id']],
            ['type' => 'artists', 'id' => '2', 'attributes' => ['name' => 'Kept']],
        ]]);

        $artists = $this->mapper->artists($document);

        $this->assertCount(1, $artists);
        $this->assertSame('Kept', $artists[0]->name);
    }

    #[Test]
    public function it_rejects_an_image_url_off_tidals_own_cdn(): void
    {
        // The URL crosses the wire in a Livewire payload and ends up in an <img src>, so a
        // tampered one must not point the browser somewhere else.
        $document = TidalDocument::from([
            'data' => [['type' => 'artists', 'id' => '1', 'attributes' => ['name' => 'A'],
                'relationships' => ['profileArt' => ['data' => ['type' => 'artworks', 'id' => 'x']]]]],
            'included' => [['type' => 'artworks', 'id' => 'x', 'attributes' => [
                'files' => [['href' => 'https://evil.example.com/320.jpg', 'meta' => ['width' => 320, 'height' => 320]]],
            ]]],
        ]);

        $this->assertNull($this->mapper->artists($document)[0]->imageUrl);
    }

    // ------------------------------------------------------------------ releases

    #[Test]
    public function it_maps_releases_with_types_dates_and_covers(): void
    {
        $releases = $this->mapper->releases($this->document('artist-releases'));

        $this->assertCount(20, $releases);

        $this->assertSame('LOCA', $releases[0]->title);
        $this->assertSame(ReleaseType::Single, $releases[0]->type);
        $this->assertSame('2026-07-10', $releases[0]->releasedOn?->toDateString());
        $this->assertStringEndsWith('/320x320.jpg', (string) $releases[0]->coverUrl);
        $this->assertStringStartsWith('https://tidal.com/browse/album/', (string) $releases[0]->link);
    }

    #[Test]
    public function it_maps_every_album_type_the_api_returns(): void
    {
        $types = array_map(
            fn ($r): string => $r->type->value,
            $this->mapper->releases($this->document('artist-releases')),
        );

        // Nothing should have fallen through to `other`: that would mean the real response
        // carries a type token ReleaseType::fromTidal does not recognise.
        $this->assertNotContains(ReleaseType::Other->value, $types);
        $this->assertContains(ReleaseType::Single->value, $types);
        $this->assertContains(ReleaseType::Album->value, $types);
    }

    #[Test]
    public function regional_pressings_share_a_variant_key(): void
    {
        $releases = $this->mapper->releases($this->document('artist-releases'));

        // Tidal lists regional pressings as separate albums: the real response returns
        // "Goals (FIFA World Cup 2026™)" three times, with identical title, date and duration.
        // Twenty entries collapse to nine.
        $keys = array_map(fn ($r): string => $r->variantKey(), $releases);

        $this->assertCount(20, $keys);
        $this->assertLessThan(20, count(array_unique($keys)));
    }

    #[Test]
    public function a_single_and_an_album_of_the_same_name_stay_distinct(): void
    {
        // Type is part of the variant key, so a single released alongside its album survives as
        // two entries rather than being collapsed into one.
        $document = TidalDocument::from(['data' => [
            ['type' => 'albums', 'id' => '1', 'attributes' => ['title' => 'LOCA', 'type' => 'SINGLE', 'releaseDate' => '2026-07-10']],
            ['type' => 'albums', 'id' => '2', 'attributes' => ['title' => 'LOCA', 'type' => 'ALBUM', 'releaseDate' => '2026-07-10']],
        ]]);

        $releases = $this->mapper->releases($document);

        $this->assertNotSame($releases[0]->variantKey(), $releases[1]->variantKey());
    }

    #[Test]
    public function an_unrecognised_album_type_becomes_other_not_album(): void
    {
        $document = TidalDocument::from(['data' => [
            ['type' => 'albums', 'id' => '1', 'attributes' => ['title' => 'T', 'type' => 'NOT_A_TIDAL_TYPE']],
        ]]);

        // Guessing Album would sort an unknown release above things that deserve the spot.
        $this->assertSame(ReleaseType::Other, $this->mapper->releases($document)[0]->type);
    }

    #[Test]
    public function a_release_with_no_date_still_maps(): void
    {
        $document = TidalDocument::from(['data' => [
            ['type' => 'albums', 'id' => '1', 'attributes' => ['title' => 'Untitled Pre-release', 'type' => 'SINGLE']],
        ]]);

        // Tidal omits the date on some regional and pre-release entries, and dropping those
        // would silently lose real new releases - the exact failure the Feed exists to stop.
        $releases = $this->mapper->releases($document);

        $this->assertSame('Untitled Pre-release', $releases[0]->title);
        $this->assertNull($releases[0]->releasedOn);
    }

    #[Test]
    public function it_drops_the_time_from_a_timestamped_release_date(): void
    {
        $document = TidalDocument::from(['data' => [
            ['type' => 'albums', 'id' => '1', 'attributes' => ['title' => 'T', 'releaseDate' => '2026-03-12T23:30:00.000+0000']],
        ]]);

        // released_on is a DATE column. Keeping a time invites a release appearing a day early
        // or late depending on the server's zone.
        $release = $this->mapper->releases($document)[0];

        $this->assertSame('2026-03-12', $release->releasedOn?->toDateString());
        $this->assertSame('00:00:00', $release->releasedOn?->format('H:i:s'));
    }

    #[Test]
    public function a_malformed_date_does_not_fail_the_import(): void
    {
        $document = TidalDocument::from(['data' => [
            ['type' => 'albums', 'id' => '1', 'attributes' => ['title' => 'T', 'releaseDate' => 'not a date at all']],
        ]]);

        $releases = $this->mapper->releases($document);

        // The release is still worth having, just unordered.
        $this->assertCount(1, $releases);
        $this->assertNull($releases[0]->releasedOn);
    }
}
