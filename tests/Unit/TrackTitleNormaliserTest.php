<?php

namespace Tests\Unit;

use App\Support\TrackTitleNormaliser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Filename in, searchable artist and title out. */
class TrackTitleNormaliserTest extends TestCase
{
    public function test_it_splits_on_the_spaced_hyphen_only(): void
    {
        $this->assertSame(['Bad Bunny', 'Monaco'], TrackTitleNormaliser::split('Bad Bunny - Monaco'));

        // A bare hyphen is part of the name. Splitting on it would turn Jay-Z into
        // artist "Jay" and title "Z".
        $this->assertSame(['', 'Jay-Z Song'], TrackTitleNormaliser::split('Jay-Z Song'));
    }

    public function test_the_first_separator_wins(): void
    {
        // Titles contain " - " far more often than artists do.
        $this->assertSame(
            ['Natti Natasha', 'Desde Hoy - NATTI NATASHA en Amargue'],
            TrackTitleNormaliser::split('Natti Natasha - Desde Hoy - NATTI NATASHA en Amargue'),
        );
    }

    public function test_a_stem_with_no_separator_is_all_title(): void
    {
        // A search on title alone still works, so this is better than guessing.
        $this->assertSame(['', 'Untitled Track'], TrackTitleNormaliser::split('Untitled Track'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function noisyTitles(): array
    {
        return [
            'official video' => ['Discúlpeme Señor (Official Video)', 'Discúlpeme Señor'],
            'nested brackets' => ['EPA (Don Diablo Remix (Audio))', 'EPA (Don Diablo Remix)'],
            'square lyrics' => ['Mi Gran Amor [Lyrics]', 'Mi Gran Amor'],
            'trailing hd' => ['Ram Pam Pam HD', 'Ram Pam Pam'],
            'trailing bare official' => ['Sin Pijamas Official Video', 'Sin Pijamas'],
            'leading track number' => ['01 Quien Sabe', '01 Quien Sabe'],
            'leading numbered dash' => ['11 - Desde Hoy', 'Desde Hoy'],
            'leading numbered dot' => ['01. Si Tu Amor No Vuelve', 'Si Tu Amor No Vuelve'],
            'junk prefixed number' => ['≡49. CRIMINAL', 'CRIMINAL'],
        ];
    }

    #[DataProvider('noisyTitles')]
    public function test_it_strips_uploader_noise(string $input, string $expected): void
    {
        $this->assertSame($expected, TrackTitleNormaliser::title($input));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function meaningfulSuffixes(): array
    {
        return [
            'acoustic' => ['Creep (Acoustic)'],
            'live' => ['Maldita Noche (Live)'],
            'remix' => ['Stay Alive (Luan x Codly Remix)'],
            'version' => ['Saberte Querer (Versión Orquesta)'],
        ];
    }

    #[DataProvider('meaningfulSuffixes')]
    public function test_it_keeps_suffixes_that_are_part_of_the_real_title(string $title): void
    {
        // The conservative half of the rule. "(Acoustic)" and "(Live)" are genuinely part of
        // MusicBrainz recording titles, so stripping them makes an exact match impossible.
        $this->assertSame($title, TrackTitleNormaliser::title($title));
    }

    public function test_a_title_that_is_entirely_noise_is_left_alone(): void
    {
        // An empty query is useless; the original at least has a chance.
        $this->assertSame('(Official Video)', TrackTitleNormaliser::title('(Official Video)'));
    }

    public function test_it_splits_the_featured_credit_off_the_title(): void
    {
        // MusicBrainz keeps featured artists in the artist credit, not the recording
        // title, so leaving this in the title clause prevents an exact match.
        [$title, $featured] = TrackTitleNormaliser::splitFeatured(
            'JETSKI REMIX (feat. PEDRO SAMPAIO, MC Meno K & Melody)'
        );

        $this->assertSame('JETSKI REMIX', $title);
        $this->assertSame('PEDRO SAMPAIO, MC Meno K & Melody', $featured);
    }

    public function test_a_title_with_no_featured_credit_is_unchanged(): void
    {
        $this->assertSame(['Monaco', ''], TrackTitleNormaliser::splitFeatured('Monaco'));
    }

    public function test_it_takes_the_first_credited_artist(): void
    {
        /*
         * yt-dlp joins artists with ", " - this is the exact string it wrote for the
         * track downloaded during Phase 8 - and MusicBrainz finds nothing for the
         * joined form.
         */
        $this->assertSame('Anitta', TrackTitleNormaliser::artist('Anitta, Los Brasileros'));
        $this->assertSame('Emilia', TrackTitleNormaliser::artist('Emilia & Nicki Nicole'));
        $this->assertSame('Tini', TrackTitleNormaliser::artist('Tini x Maria Becerra'));
        $this->assertSame('Becky G', TrackTitleNormaliser::artist('Becky G feat. Natti Natasha'));
    }

    public function test_a_leading_separator_is_not_a_split_point(): void
    {
        // Otherwise an artist beginning with one of the separators becomes an empty
        // string and the artist clause silently disappears.
        $this->assertSame('& Juliet', TrackTitleNormaliser::artist('& Juliet'));
    }

    public function test_from_filename_does_the_whole_job(): void
    {
        $parsed = TrackTitleNormaliser::fromFilename(
            'Emilia, Nicki Nicole - JETSKI REMIX (feat. PEDRO SAMPAIO) (Official Video)'
        );

        $this->assertSame('Emilia', $parsed['artist']);
        $this->assertSame('JETSKI REMIX', $parsed['title']);
        $this->assertSame('PEDRO SAMPAIO', $parsed['featured']);
    }

    public function test_similarity_ignores_case_accents_and_punctuation(): void
    {
        /*
         * similar_text() is byte-based, so on multi-byte titles it scores oddly and
         * counts a differing accent as a real difference. The legacy "Best Match" flag
         * compared raw strings and suffered for it.
         */
        $this->assertSame(100.0, TrackTitleNormaliser::similarity('Você Ja Sabe', 'voce ja sabe'));
        $this->assertSame(100.0, TrackTitleNormaliser::similarity('Creep', 'creep!'));
        $this->assertSame(0.0, TrackTitleNormaliser::similarity('Creep', ''));
    }

    public function test_similarity_still_separates_a_near_match_from_a_wrong_one(): void
    {
        $near = TrackTitleNormaliser::similarity('Creep', 'Creep (live)');
        $wrong = TrackTitleNormaliser::similarity('Creep', 'Paranoid Android');

        $this->assertGreaterThan($wrong, $near);
        $this->assertGreaterThan(50.0, $near);
    }
}
