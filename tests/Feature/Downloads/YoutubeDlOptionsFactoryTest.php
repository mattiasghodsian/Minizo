<?php

namespace Tests\Feature\Downloads;

use App\Enums\AudioFormat;
use App\Services\Download\YoutubeDlOptionsFactory;
use App\Support\DownloadRequest;
use App\Support\LibraryFolder;
use Tests\TestCase;
use YoutubeDl\Process\ArgvBuilder;

/** The yt-dlp command line, asserted as argv. */
class YoutubeDlOptionsFactoryTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    private function argv(?AudioFormat $format = null): array
    {
        $request = new DownloadRequest(
            url: 'https://music.youtube.com/watch?v=abc12345678',
            folder: new LibraryFolder('Spanish'),
            format: $format ?? AudioFormat::Flac,
        );

        return ArgvBuilder::build(
            app(YoutubeDlOptionsFactory::class)->for($request, '/music/Spanish')
        );
    }

    public function test_it_never_passes_audio_quality_for_a_lossless_format(): void
    {
        // yt-dlp only applies --audio-quality to lossy encoders, so passing it for FLAC
        // does nothing while looking like a quality setting.
        $argv = $this->argv();

        $this->assertEmpty(
            array_filter($argv, fn (string $arg): bool => str_starts_with($arg, '--audio-quality')),
            'audio-quality is a no-op for FLAC and must not be passed',
        );
    }

    public function test_the_flac_compression_level_is_passed_to_the_postprocessor(): void
    {
        // The actual quality knob for a lossless format.
        config()->set('minizo.downloads.flac_compression_level', 12);

        $this->assertContains(
            '--postprocessor-args=ExtractAudio:-compression_level 12',
            $this->argv(),
        );
    }

    public function test_the_compression_level_is_clamped_to_what_ffmpeg_accepts(): void
    {
        config()->set('minizo.downloads.flac_compression_level', 99);

        $this->assertContains(
            '--postprocessor-args=ExtractAudio:-compression_level 12',
            $this->argv(),
        );
    }

    public function test_a_playlist_url_downloads_only_the_one_track(): void
    {
        // Without this, a URL copied out of a playlist fetches the entire playlist
        // under a single queue row. Stripping "&list=" from the URL instead would rewrite
        // what the user pasted, and breaks on a different parameter order.
        $this->assertContains('--no-playlist', $this->argv());
    }

    public function test_it_does_not_transliterate_filenames(): void
    {
        // --restrict-filenames would rewrite "Perdonarte ¿Para Qué?" as "Perdonarte_Para_Que".
        // --windows-filenames strips only what a Windows host cannot store, which matters
        // because the music disk is a bind mount from one.
        $argv = $this->argv();

        $this->assertNotContains('--restrict-filenames', $argv);
        $this->assertContains('--windows-filenames', $argv);
    }

    public function test_it_never_overwrites_an_existing_track(): void
    {
        $this->assertContains('--no-overwrites', $this->argv());
    }

    public function test_it_extracts_audio_in_the_requested_format_with_cover_art(): void
    {
        $argv = $this->argv();

        $this->assertContains('--extract-audio', $argv);
        $this->assertContains('--audio-format=flac', $argv);
        $this->assertContains('--embed-thumbnail', $argv);

        // yt-dlp's name for this is --add-metadata; there is no embedMetadata().
        $this->assertContains('--add-metadata', $argv);
    }

    public function test_the_output_template_is_rooted_at_the_destination_folder(): void
    {
        // Options::toArray() concatenates downloadPath and output into one --output,
        // so this is the only assertion that proves the file lands in the folder.
        $this->assertContains(
            '--output=/music/Spanish/'.YoutubeDlOptionsFactory::OUTPUT_TEMPLATE,
            $this->argv(),
        );
    }

    public function test_the_output_template_falls_back_when_the_artist_is_unknown(): void
    {
        // Plain YouTube has no `artist` field. The legacy template produced a leading
        // " - " on every such download.
        $this->assertStringContainsString(
            '%(artist,artists,uploader|Unknown Artist)s',
            YoutubeDlOptionsFactory::OUTPUT_TEMPLATE,
        );
    }

    public function test_the_url_is_the_last_argument(): void
    {
        $argv = $this->argv();

        $this->assertSame('https://music.youtube.com/watch?v=abc12345678', end($argv));
    }
}
