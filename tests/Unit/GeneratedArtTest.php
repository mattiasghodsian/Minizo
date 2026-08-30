<?php

namespace Tests\Unit;

use App\Support\GeneratedArt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GeneratedArtTest extends TestCase
{
    /**
     * Expected hues produced by running the design prototype's own JavaScript (Minizo Redesign.dc.html, line 770) over these strings:
     *
     * @return array<string, array{string, int}>
     */
    public static function prototypeHues(): array
    {
        return [
            'Spanish' => ['Spanish', 36],
            'Folk' => ['Folk', 216],
            'GameBT' => ['GameBT', 348],
            'Asian' => ['Asian', 132],
            'minizo' => ['minizo', 332],
            'maria' => ['maria', 102],
            'kev' => ['kev', 236],
            'lena' => ['lena', 236],
            'Anitta' => ['Anitta', 69],
            'Unprocessed' => ['Unprocessed', 173],
            'empty string' => ['', 0],
            'latin-1 supplement' => ['Ö', 214],
        ];
    }

    #[DataProvider('prototypeHues')]
    public function test_hue_matches_the_design_prototype(string $value, int $expected): void
    {
        $this->assertSame($expected, GeneratedArt::hue($value));
    }

    public function test_hue_is_always_a_valid_css_hue(): void
    {
        foreach (['', 'a', 'Spanish', 'A very long folder name with spaces', '日本語', '🎵'] as $value) {
            $hue = GeneratedArt::hue($value);

            $this->assertGreaterThanOrEqual(0, $hue, "hue($value) below range");
            $this->assertLessThan(360, $hue, "hue($value) above range");
        }
    }

    public function test_hue_is_stable_and_differs_between_names(): void
    {
        // Stability is the whole point: a folder must not change colour between
        // requests, deploys or PHP versions.
        $this->assertSame(GeneratedArt::hue('Spanish'), GeneratedArt::hue('Spanish'));

        $this->assertNotSame(GeneratedArt::hue('Spanish'), GeneratedArt::hue('Folk'));

        // Case matters - "minizo" and "Minizo" are different strings and the
        // prototype hashes them differently. Documented rather than "fixed".
        $this->assertNotSame(GeneratedArt::hue('minizo'), GeneratedArt::hue('Minizo'));
    }

    public function test_collisions_are_possible_and_acceptable(): void
    {
        // 360 buckets means collisions are inevitable; this is a real one from the
        // design's own fixtures. Asserted so nobody "fixes" the hash later
        // believing collisions are a bug.
        $this->assertSame(GeneratedArt::hue('kev'), GeneratedArt::hue('lena'));
    }

    public function test_initial_is_a_single_uppercase_character(): void
    {
        $this->assertSame('S', GeneratedArt::initial('Spanish'));
        $this->assertSame('M', GeneratedArt::initial('minizo'));
        $this->assertSame('Ö', GeneratedArt::initial('Östergötland'));

        // Leading whitespace must not become the initial.
        $this->assertSame('F', GeneratedArt::initial('  Folk'));
    }

    public function test_initial_is_empty_for_a_blank_name(): void
    {
        $this->assertSame('', GeneratedArt::initial(''));
        $this->assertSame('', GeneratedArt::initial('   '));
    }

    public function test_initial_handles_multibyte_without_splitting_bytes(): void
    {
        // mb_substr, not substr: the latter would return half a UTF-8 sequence and
        // render as a replacement character.
        $this->assertSame('日', GeneratedArt::initial('日本語'));
        $this->assertSame(1, mb_strlen(GeneratedArt::initial('日本語')));
    }
}
