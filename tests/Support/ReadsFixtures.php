<?php

namespace Tests\Support;

/** Reads the recorded API responses under tests/Fixtures. */
trait ReadsFixtures
{
    /**
     * A fixture decoded to an array, named relative to tests/Fixtures without the extension.
     *
     * @return array<string, mixed>
     */
    protected function fixture(string $path): array
    {
        $json = file_get_contents(base_path("tests/Fixtures/{$path}.json"));

        return json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * A Tidal fixture.
     *
     * @return array<string, mixed>
     */
    protected function tidalFixture(string $name): array
    {
        return $this->fixture("tidal/{$name}");
    }

    /**
     * A MusicBrainz fixture.
     *
     * @return array<string, mixed>
     */
    protected function musicBrainzFixture(string $name): array
    {
        return $this->fixture("musicbrainz/{$name}");
    }
}
