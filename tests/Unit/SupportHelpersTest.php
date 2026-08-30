<?php

namespace Tests\Unit;

use App\Support\Duration;
use App\Support\FileSize;
use App\Support\Mbid;
use App\Support\Scalar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The small shared helpers in app/Support. */
class SupportHelpersTest extends TestCase
{
    // ------------------------------------------------------------------ Duration

    #[Test]
    #[DataProvider('clockCases')]
    public function it_formats_seconds_as_a_clock(int|float|null $seconds, ?string $expected): void
    {
        $this->assertSame($expected, Duration::clock($seconds));
    }

    /**
     * @return array<string, array{0: int|float|null, 1: ?string}>
     */
    public static function clockCases(): array
    {
        return [
            'whole minutes' => [180, '3:00'],
            'pads seconds' => [125, '2:05'],
            'under a minute' => [7, '0:07'],
            'rounds' => [124.6, '2:05'],
            'over an hour stays in minutes' => [3661, '61:01'],
            'null' => [null, null],
            'zero' => [0, null],
            'negative' => [-5, null],
        ];
    }

    #[Test]
    public function it_formats_milliseconds_as_a_clock(): void
    {
        $this->assertSame('2:05', Duration::clockFromMs(125_000));
        $this->assertNull(Duration::clockFromMs(null));
        $this->assertNull(Duration::clockFromMs(0));
    }

    // ------------------------------------------------------------------ FileSize

    #[Test]
    #[DataProvider('sizeCases')]
    public function it_labels_byte_counts(int $bytes, string $expected): void
    {
        $this->assertSame($expected, FileSize::label($bytes));
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function sizeCases(): array
    {
        return [
            'zero' => [0, '0 MB'],
            'negative' => [-1, '0 MB'],
            'megabytes' => [44_150_000, '42.10 MB'],
            // number_format groups thousands, so the MB branch reads "1,024.00" right up to
            // the switch. Matches what the Files listing has always shown.
            'just under a gigabyte' => [1_073_741_823, '1,024.00 MB'],
            'switches at a gigabyte' => [1_073_741_824, '1.00 GB'],
            'gigabytes' => [5_368_709_120, '5.00 GB'],
        ];
    }

    // ---------------------------------------------------------------------- Mbid

    #[Test]
    public function it_recognises_a_musicbrainz_identifier(): void
    {
        $this->assertTrue(Mbid::isValid('52fa0b53-4bad-4bbe-b23b-d82233500fc7'));
        $this->assertTrue(Mbid::isValid('52FA0B53-4BAD-4BBE-B23B-D82233500FC7'));
        $this->assertTrue(Mbid::isValid('  52fa0b53-4bad-4bbe-b23b-d82233500fc7  '));
    }

    #[Test]
    #[DataProvider('badMbids')]
    public function it_rejects_anything_else(string $value): void
    {
        $this->assertFalse(Mbid::isValid($value));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badMbids(): array
    {
        return [
            'empty' => [''],
            'plain words' => ['not-a-uuid'],
            'wrong group lengths' => ['52fa0b5-4bad-4bbe-b23b-d82233500fc7'],
            'non-hex' => ['52fa0b53-4bad-4bbe-b23b-z82233500fc7'],
            'trailing junk' => ['52fa0b53-4bad-4bbe-b23b-d82233500fc7x'],
            'no hyphens' => ['52fa0b534bad4bbeb23bd82233500fc7'],
        ];
    }

    // -------------------------------------------------------------------- Scalar

    #[Test]
    public function it_trims_scalars_to_a_string(): void
    {
        $this->assertSame('Radiohead', Scalar::stringOrNull('  Radiohead  '));
        $this->assertSame('1993', Scalar::stringOrNull(1993));
        $this->assertSame('1', Scalar::stringOrNull(true));
    }

    #[Test]
    public function it_returns_null_for_empty_values(): void
    {
        $this->assertNull(Scalar::stringOrNull(null));
        $this->assertNull(Scalar::stringOrNull(''));
        $this->assertNull(Scalar::stringOrNull('   '));
    }

    #[Test]
    public function it_returns_null_rather_than_casting_a_non_scalar(): void
    {
        // The reason the three copies this replaced were consolidated on this form: two of
        // them used filled(), which lets a populated array reach a (string) cast and fail.
        $this->assertNull(Scalar::stringOrNull(['a', 'b']));
        $this->assertNull(Scalar::stringOrNull(new \stdClass));
    }

    #[Test]
    public function it_reads_integers_only_from_numeric_values(): void
    {
        $this->assertSame(188000, Scalar::intOrNull(188000));
        $this->assertSame(188000, Scalar::intOrNull('188000'));
        $this->assertSame(2, Scalar::intOrNull(2.7));
        $this->assertNull(Scalar::intOrNull('two'));
        $this->assertNull(Scalar::intOrNull(null));
        $this->assertNull(Scalar::intOrNull([]));
    }
}
