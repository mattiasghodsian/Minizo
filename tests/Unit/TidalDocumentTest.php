<?php

namespace Tests\Unit;

use App\Services\Tidal\TidalDocument;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ReadsFixtures;
use Tests\TestCase;

/** The JSON:API `included` join. */
class TidalDocumentTest extends TestCase
{
    use ReadsFixtures;

    #[Test]
    public function it_resolves_relationship_identifiers_against_included(): void
    {
        $document = TidalDocument::from($this->tidalFixture('artist-search'));

        $artists = $document->relatedTo('artists', 'artists');

        $this->assertCount(20, $artists);

        // The identifier under data.relationships carried only a type and an id; the name came
        // from the join. This is the assertion the whole class exists for.
        $this->assertSame('Anitta', $document->attribute($artists[0], 'name'));
    }

    #[Test]
    public function relationship_order_is_relevance_order_and_included_order_is_not(): void
    {
        $document = TidalDocument::from($this->tidalFixture('artist-search'));

        $viaRelationship = array_map(
            fn (array $r): mixed => $document->attribute($r, 'name'),
            $document->relatedTo('artists', 'artists'),
        );

        $viaIncluded = array_map(
            fn (array $r): mixed => $document->attribute($r, 'name'),
            $document->included('artists'),
        );

        // Searching "ANITTA" against the live API puts Anitta first in
        // data.relationships.artists.data and seventh in `included`, which is ordered by id.
        $this->assertSame('Anitta', $viaRelationship[0]);
        $this->assertNotSame('Anitta', $viaIncluded[0]);
    }

    #[Test]
    public function included_is_keyed_by_type_and_id_not_id_alone(): void
    {
        // Written inline: in JSON:API ids are scoped to a type, so an album and an artist may
        // legitimately share "12345", and an index keyed on id alone silently loses one.
        $document = TidalDocument::from(['included' => [
            ['type' => 'artists', 'id' => '12345', 'attributes' => ['name' => 'The artist']],
            ['type' => 'albums', 'id' => '12345', 'attributes' => ['title' => 'The album']],
        ]]);

        $artist = $document->resolve(['type' => 'artists', 'id' => '12345']);
        $album = $document->resolve(['type' => 'albums', 'id' => '12345']);

        $this->assertSame('The artist', $document->attribute((array) $artist, 'name'));
        $this->assertSame('The album', $document->attribute((array) $album, 'title'));
    }

    #[Test]
    public function it_filters_included_by_type(): void
    {
        $document = TidalDocument::from($this->tidalFixture('artist-search'));

        // A nested include (artists.profileArt) puts two types in one document: the artists
        // and the artworks their pictures live on.
        $this->assertCount(20, $document->included('artists'));
        $this->assertCount(20, $document->included('artworks'));
        $this->assertSame([], $document->included('albums'));
    }

    #[Test]
    public function it_skips_identifiers_that_are_not_in_included(): void
    {
        // What a caller who forgot ?include= gets back. A half-built artist card is worse than
        // one fewer result, so the identifier is dropped rather than defaulted.
        $document = TidalDocument::from([
            'data' => [
                'type' => 'searchResults',
                'id' => 'X',
                'relationships' => ['artists' => ['data' => [['type' => 'artists', 'id' => '1']]]],
            ],
        ]);

        $this->assertSame([], $document->relatedTo('artists', 'artists'));
    }

    #[Test]
    public function it_normalises_single_resources_and_collections(): void
    {
        // Both shapes are real: searchResults returns a single primary resource, and
        // artists/{id}/relationships/albums returns a list.
        $single = TidalDocument::from($this->tidalFixture('artist-search'));
        $list = TidalDocument::from($this->tidalFixture('artist-releases'));

        $this->assertSame('searchResults', $single->data()['type']);
        $this->assertCount(1, $single->collection());

        $this->assertSame('albums', $list->data()['type']);
        $this->assertCount(20, $list->collection());
    }

    #[Test]
    public function a_collection_of_bare_identifiers_carries_no_attributes(): void
    {
        $list = TidalDocument::from($this->tidalFixture('artist-releases'));

        // Worth pinning: `data` here is identifiers only, so anything reading
        // data[0].attributes.title finds nothing. The title is in `included`.
        $this->assertArrayNotHasKey('attributes', $list->collection()[0]);
        $this->assertNotNull($list->resolve($list->collection()[0]));
    }

    #[Test]
    public function it_reads_to_one_relationships(): void
    {
        // A to-one relationship carries an object where to-many carries a list. Both are legal
        // JSON:API and Tidal uses both - profileArt is to-one.
        $document = TidalDocument::from($this->tidalFixture('artist-search'));

        $artist = $document->relatedTo('artists', 'artists')[0];
        $artwork = $document->identifiers($artist, 'profileArt');

        $this->assertCount(1, $artwork);
        $this->assertSame('artworks', $artwork[0]['type']);
        $this->assertNotNull($document->resolve($artwork[0]));
    }

    #[Test]
    public function it_exposes_the_next_pagination_link(): void
    {
        $this->assertStringContainsString(
            'page%5Bcursor%5D',
            (string) TidalDocument::from($this->tidalFixture('artist-releases'))->nextLink(),
        );

        // A searchResults document has no top-level next link: its pagination lives on
        // the relationship, under data.relationships.artists.links.next. Search asks for
        // one page of twenty and stops, so that link is not followed.
        $this->assertNull(TidalDocument::from($this->tidalFixture('artist-search'))->nextLink());
    }

    #[Test]
    public function it_survives_a_null_or_malformed_body(): void
    {
        // The client returns null on a transport failure, and this must not be the thing that
        // turns that into a 500.
        $this->assertTrue(TidalDocument::from(null)->isEmpty());
        $this->assertSame([], TidalDocument::from(null)->data());

        $malformed = TidalDocument::from([
            'data' => 'not an array',
            'included' => ['also not an array', ['type' => 'artists'], ['id' => '1']],
        ]);

        $this->assertSame([], $malformed->collection());
        $this->assertSame([], $malformed->included('artists'));
    }
}
