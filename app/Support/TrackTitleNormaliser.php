<?php

namespace App\Support;

use Illuminate\Support\Str;

final class TrackTitleNormaliser
{
    /** Parenthesised or bracketed tokens that are never part of a release title. */
    private const NOISE = [
        'official video', 'official music video', 'official audio', 'official lyric video',
        'official visualizer', 'official video hd', 'music video', 'lyric video', 'lyrics',
        'letra', 'audio', 'visualizer', 'video oficial', 'audio oficial', 'clip officiel',
        'hd', 'hq', '4k', 'full hd', 'with lyrics', 'free download', 'explicit',
    ];

    /**
     * Split a "Artist - Title" filename stem into its two halves.
     *
     * @return array{0: string, 1: string} [artist, title]. Artist is empty when there
     *                                     is no separator, in which case the whole stem is the title - a search on
     *                                     title alone still works.
     */
    public static function split(string $stem): array
    {
        $stem = trim(preg_replace('/\s+/u', ' ', $stem) ?? '');

        $position = mb_strpos($stem, ' - ');

        if ($position === false) {
            return ['', $stem];
        }

        return [
            trim(mb_substr($stem, 0, $position)),
            trim(mb_substr($stem, $position + 3)),
        ];
    }

    /** Strip uploader noise from a title. */
    public static function title(string $title): string
    {
        $clean = $title;

        // Bracketed noise, in either bracket style. Case-insensitive, and repeated
        // because tokens nest: "(Don Diablo Remix (Audio))".
        for ($pass = 0; $pass < 3; $pass++) {
            $before = $clean;

            foreach (self::NOISE as $token) {
                $pattern = '/[\(\[]\s*'.preg_quote($token, '/').'\s*[\)\]]/iu';
                $clean = preg_replace($pattern, ' ', $clean) ?? $clean;
            }

            if ($clean === $before) {
                break;
            }
        }

        // Trailing bare noise: "… HD", "… 4K", "… Official Video" with no brackets.
        $clean = preg_replace('/\s+(official\s+(music\s+)?video|official\s+audio|lyrics|hd|hq|4k)\s*$/iu', '', $clean) ?? $clean;

        // Leading track numbers, which yt-dlp picks up from playlist titles:
        // "11 - Desde Hoy", "01. Quien Sabe", "≡49. CRIMINAL".
        $clean = preg_replace('/^\s*[^\p{L}\d]*\d{1,3}\s*[-.)]\s+/u', '', $clean) ?? $clean;

        // Empty brackets left by the removals above.
        $clean = preg_replace('/[\(\[]\s*[\)\]]/u', ' ', $clean) ?? $clean;

        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '');

        // Tidy the gap a removal leaves inside a surviving bracket: "EPA (Don Diablo
        // Remix (Audio))" would otherwise yield "EPA (Don Diablo Remix )", and the stray
        // space goes into a phrase that has to match exactly.
        $clean = preg_replace('/\s+([\)\]])/u', '$1', $clean) ?? $clean;
        $clean = preg_replace('/([\(\[])\s+/u', '$1', $clean) ?? $clean;

        // Never return nothing: if the rules ate the whole title, the original is a
        // better query than an empty one.
        return $clean !== '' ? trim($clean, ' -–—·') : trim($title);
    }

    /**
     * The featured-artists suffix, split off from the title.
     *
     * @return array{0: string, 1: string} [title without the suffix, featured names]
     */
    public static function splitFeatured(string $title): array
    {
        $pattern = '/\s*[\(\[]?\s*(?:feat\.?|ft\.?|featuring|con)\s+(?<names>[^\)\]]+)[\)\]]?\s*$/iu';

        if (preg_match($pattern, $title, $match) !== 1) {
            return [trim($title), ''];
        }

        return [
            trim((string) preg_replace($pattern, '', $title)),
            trim($match['names']),
        ];
    }

    /**
     * Everything at once: a filename stem in, a search-ready artist and title out.
     *
     * @return array{artist: string, title: string, featured: string}
     */
    public static function fromFilename(string $stem): array
    {
        [$artist, $title] = self::split($stem);

        [$title, $featured] = self::splitFeatured(self::title($title));

        return [
            'artist' => self::artist($artist),
            'title' => $title,
            'featured' => $featured,
        ];
    }

    /** Tidy an artist string: take the first credited name only. */
    public static function artist(string $artist): string
    {
        $artist = trim(preg_replace('/\s+/u', ' ', $artist) ?? '');

        if ($artist === '') {
            return '';
        }

        [$artist] = self::splitFeatured($artist);

        foreach ([',', ' & ', ' x ', ' X ', ' vs. ', ' vs '] as $separator) {
            $position = mb_strpos($artist, $separator);

            if ($position !== false && $position > 0) {
                $artist = mb_substr($artist, 0, $position);
            }
        }

        return trim($artist);
    }

    /** Similarity between two titles, 0-100. */
    public static function similarity(string $a, string $b): float
    {
        $a = self::comparable($a);
        $b = self::comparable($b);

        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 100.0;
        }

        similar_text($a, $b, $percent);

        return round($percent, 2);
    }

    /** The form two titles are compared in: lowercase, unpunctuated, collapsed. */
    private static function comparable(string $value): string
    {
        $value = Str::lower(Str::ascii($value));

        return trim(preg_replace('/[^a-z0-9 ]+/', ' ', $value) ?? '');
    }
}
