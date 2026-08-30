<?php

namespace App\Services\MusicBrainz;

use App\Support\ReleaseCandidate;

class ReleaseCandidateRanker
{
    /** Countries whose releases tend to carry the fullest metadata. */
    private const PREFERRED_COUNTRIES = ['XW', 'US', 'GB'];

    /**
     * @param  array<int, ReleaseCandidate>  $candidates
     * @return array<int, ReleaseCandidate>
     */
    public function rank(array $candidates): array
    {
        // Comparing the original index last makes the order total, so two candidates
        // with identical keys cannot swap between page loads.
        $indexed = array_values($candidates);
        $positions = [];

        foreach ($indexed as $index => $candidate) {
            $positions[spl_object_id($candidate)] = $index;
        }

        usort($indexed, function (ReleaseCandidate $a, ReleaseCandidate $b) use ($positions): int {
            return $this->official($b) <=> $this->official($a)
                ?: $b->type->weight() <=> $a->type->weight()
                ?: $b->score <=> $a->score
                ?: $a->trackCount <=> $b->trackCount
                ?: $this->countryRank($a) <=> $this->countryRank($b)
                ?: $positions[spl_object_id($a)] <=> $positions[spl_object_id($b)];
        });

        return $indexed;
    }

    /**
     * Drop duplicates, keeping the first occurrence.
     *
     * @param  array<int, ReleaseCandidate>  $candidates
     * @return array<int, ReleaseCandidate>
     */
    public function dedupe(array $candidates): array
    {
        $seen = [];
        $unique = [];

        foreach ($candidates as $candidate) {
            if (isset($seen[$candidate->key()])) {
                continue;
            }

            $seen[$candidate->key()] = true;
            $unique[] = $candidate;
        }

        return $unique;
    }

    /** Sort key putting official releases ahead of bootlegs and promos. */
    private function official(ReleaseCandidate $candidate): int
    {
        // A standalone recording has no release status at all, and must not be
        // penalised for it - it is the only candidate that can be right when the
        // track genuinely belongs to no release.
        if ($candidate->isStandalone()) {
            return 1;
        }

        return strcasecmp((string) $candidate->status, 'Official') === 0 ? 1 : 0;
    }

    /** Sort key preferring the configured countries, then worldwide. */
    private function countryRank(ReleaseCandidate $candidate): int
    {
        $position = array_search(strtoupper((string) $candidate->country), self::PREFERRED_COUNTRIES, true);

        return $position === false ? count(self::PREFERRED_COUNTRIES) : $position;
    }
}
