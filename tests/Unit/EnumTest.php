<?php

namespace Tests\Unit;

use App\Enums\AudioFormat;
use App\Enums\DownloadStatus;
use App\Enums\ShareExpiry;
use App\Enums\ShareType;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class EnumTest extends TestCase
{
    public function test_flac_is_the_only_supported_audio_format(): void
    {
        // Guards the FLAC-only decision. If a format is added, the tag writer for
        // it has to exist too - this failing is the reminder.
        $this->assertSame(['flac'], array_map(
            fn (AudioFormat $f): string => $f->value,
            AudioFormat::cases(),
        ));

        $this->assertSame(AudioFormat::Flac, AudioFormat::default());
        $this->assertSame(['flac'], AudioFormat::extensions());
        $this->assertTrue(AudioFormat::Flac->isLossless());
    }

    public function test_audio_format_resolves_from_a_filename(): void
    {
        $this->assertSame(AudioFormat::Flac, AudioFormat::fromFilename('Emilia - GTA.flac'));
        $this->assertSame(AudioFormat::Flac, AudioFormat::fromFilename('UPPER.FLAC'));

        $this->assertNull(AudioFormat::fromFilename('cover.jpg'));
        $this->assertNull(AudioFormat::fromFilename('no-extension'));
        // A dotted directory-ish name must not be read as an extension match.
        $this->assertNull(AudioFormat::fromFilename('Artist - Song.mp3'));
    }

    public function test_only_queued_and_running_are_non_terminal(): void
    {
        foreach (DownloadStatus::cases() as $status) {
            $this->assertSame(
                in_array($status, [DownloadStatus::Queued, DownloadStatus::Running], true),
                ! $status->isTerminal(),
                "{$status->value} terminality is wrong",
            );
        }

        $this->assertSame(
            [DownloadStatus::Queued, DownloadStatus::Running],
            DownloadStatus::inFlight(),
        );
    }

    public function test_only_a_running_download_is_active(): void
    {
        $this->assertTrue(DownloadStatus::Running->isActive());
        $this->assertFalse(DownloadStatus::Queued->isActive());
        $this->assertFalse(DownloadStatus::Completed->isActive());
    }

    public function test_every_download_status_maps_to_a_distinct_design_token(): void
    {
        $tones = array_map(fn (DownloadStatus $s): string => $s->tone(), DownloadStatus::cases());

        $this->assertCount(count(DownloadStatus::cases()), array_unique($tones));

        // Queued is grey, not the accent - the design distinguishes "waiting"
        // from "moving" by colour rather than only by bar width.
        $this->assertSame('progress-queued', DownloadStatus::Queued->tone());
        $this->assertSame('brand', DownloadStatus::Running->tone());
    }

    public function test_share_type_copy_matches_the_design(): void
    {
        $this->assertSame('SHARED FOLDER', ShareType::Folder->kicker());
        $this->assertSame('SHARED TRACK', ShareType::Track->kicker());

        $this->assertSame('Download all (.zip)', ShareType::Folder->downloadLabel());
        $this->assertSame('Download track', ShareType::Track->downloadLabel());

        $this->assertTrue(ShareType::Folder->isCollection());
        $this->assertFalse(ShareType::Track->isCollection());
    }

    public function test_share_expiry_offers_the_designs_five_options(): void
    {
        $this->assertSame(
            ['1 hour', '6 hours', '24 hours', '72 hours', '7 days'],
            array_map(fn (ShareExpiry $e): string => $e->label(), ShareExpiry::cases()),
        );

        $this->assertSame(ShareExpiry::OneDay, ShareExpiry::default());
        $this->assertSame([3600, 21600, 86400, 259200, 604800], array_keys(ShareExpiry::options()));
    }

    public function test_share_expiry_resolves_against_a_given_instant(): void
    {
        $from = new CarbonImmutable('2026-07-26 12:00:00');

        $this->assertSame(
            '2026-07-27 12:00:00',
            ShareExpiry::OneDay->toDate($from)->format('Y-m-d H:i:s'),
        );

        $this->assertSame(
            '2026-08-02 12:00:00',
            ShareExpiry::OneWeek->toDate($from)->format('Y-m-d H:i:s'),
        );

        $this->assertSame(3600, ShareExpiry::OneHour->seconds());
    }
}
