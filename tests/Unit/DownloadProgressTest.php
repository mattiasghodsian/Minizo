<?php

namespace Tests\Unit;

use App\Support\DownloadProgress;
use PHPUnit\Framework\TestCase;

/** yt-dlp's progress lines, as youtube-dl-php hands them over: six strings, three of which can be null or a literal "Unknown …" placeholder. */
class DownloadProgressTest extends TestCase
{
    public function test_it_parses_a_typical_progress_line(): void
    {
        $progress = DownloadProgress::fromCallback(
            'Bad Bunny - Monaco.webm', '43.7%', '38.40MiB', '2.10MiB/s', '00:12',
        );

        // Rounded, not truncated: 43.7% reads as 44 on a bar.
        $this->assertSame(44, $progress->percent);
        $this->assertSame('38.40MiB', $progress->size);
        $this->assertSame('2.10MiB/s', $progress->speed);
        $this->assertSame('00:12', $progress->eta);
        $this->assertSame('Bad Bunny - Monaco.webm', $progress->target);
    }

    public function test_it_drops_the_unknown_placeholders(): void
    {
        // yt-dlp prints these words rather than omitting the field, and "Unknown
        // speed" in the UI is worse than an empty space.
        $progress = DownloadProgress::fromCallback(
            null, '0.0%', '~4.53MiB', 'Unknown speed', 'Unknown ETA',
        );

        $this->assertNull($progress->speed);
        $this->assertNull($progress->eta);
        $this->assertSame('~4.53MiB', $progress->size);
    }

    public function test_the_percentage_is_clamped(): void
    {
        // A postprocessing line can report past 100, and a bar wider than its track
        // overflows the row.
        $this->assertSame(100, DownloadProgress::fromCallback(null, '104.2%')->percent);
        $this->assertSame(0, DownloadProgress::fromCallback(null, '-3%')->percent);
    }

    public function test_labels_stay_strings_in_the_units_yt_dlp_reported(): void
    {
        // MiB is not MB. Converting for prettier numbers would misreport the size by
        // ~5%, and nothing in the app does arithmetic on these values.
        $progress = DownloadProgress::fromCallback(null, '100%', '38.40MiB', '1.20GiB/s');

        $this->assertSame('38.40MiB', $progress->size);
        $this->assertSame('1.20GiB/s', $progress->speed);
    }
}
