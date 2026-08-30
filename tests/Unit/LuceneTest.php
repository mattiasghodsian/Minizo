<?php

namespace Tests\Unit;

use App\Support\Lucene;
use PHPUnit\Framework\TestCase;

/** The query-building layer. See App\Support\Lucene for the measured result counts. */
class LuceneTest extends TestCase
{
    public function test_a_field_becomes_a_quoted_phrase_not_a_range(): void
    {
        // `artist:[Radiohead]` is Lucene range syntax, not a name, so it asks for an
        // open-ended range and matches effectively the whole database.
        $this->assertSame('artist:"Radiohead"', Lucene::field('artist', 'Radiohead'));
        $this->assertStringNotContainsString('[', Lucene::field('artist', 'Radiohead'));
    }

    public function test_a_phrase_survives_the_characters_that_break_a_bare_term(): void
    {
        // Real titles are full of these: "Perdonarte ¿Para Qué?", "EPA (Don Diablo
        // Remix)", "tqum (feat. Danna Paola & Kim Petras) [Remix]".
        $this->assertSame('"Perdonarte ¿Para Qué?"', Lucene::phrase('Perdonarte ¿Para Qué?'));
        $this->assertSame('"EPA (Don Diablo Remix)"', Lucene::phrase('EPA (Don Diablo Remix)'));
        $this->assertSame('"A + B / C"', Lucene::phrase('A + B / C'));
    }

    public function test_a_phrase_escapes_only_quotes_and_backslashes(): void
    {
        // Inside a phrase those are the only two special characters, which is why
        // phrases are preferred over escaping every term.
        $this->assertSame('"say \\"hello\\""', Lucene::phrase('say "hello"'));
        $this->assertSame('"back\\\\slash"', Lucene::phrase('back\\slash'));
    }

    public function test_an_empty_value_produces_no_clause_at_all(): void
    {
        // Not `artist:""`, which matches nothing and looks like a server fault.
        $this->assertSame('', Lucene::phrase('   '));
        $this->assertSame('', Lucene::field('artist', ''));
    }

    public function test_clauses_are_joined_with_and_and_blanks_are_dropped(): void
    {
        // A missing artist must not leave a trailing " AND ", which the server rejects.
        $this->assertSame(
            'recording:"Creep" AND artist:"Radiohead"',
            Lucene::all([Lucene::field('recording', 'Creep'), Lucene::field('artist', 'Radiohead')]),
        );

        $this->assertSame(
            'recording:"Creep"',
            Lucene::all([Lucene::field('recording', 'Creep'), Lucene::field('artist', '')]),
        );

        $this->assertSame('', Lucene::all(['', '   ']));
    }

    public function test_whitespace_inside_a_phrase_is_collapsed(): void
    {
        $this->assertSame('"Bad Bunny"', Lucene::phrase("Bad   \n Bunny"));
    }

    public function test_escape_handles_the_backslash_first(): void
    {
        // Escaping in the wrong order double-escapes everything added after the
        // backslash, producing a query the server reads differently.
        $this->assertSame('a\\\\b\\+c', Lucene::escape('a\\b+c'));
    }

    public function test_the_standalone_filter_survives_being_combined(): void
    {
        // -reid:* is a raw clause, not a value, so it must pass through untouched -
        // escaping the asterisk would turn the filter into a literal search.
        $this->assertSame(
            'artist:"Radiohead" AND -reid:*',
            Lucene::all([Lucene::field('artist', 'Radiohead'), '-reid:*']),
        );
    }
}
