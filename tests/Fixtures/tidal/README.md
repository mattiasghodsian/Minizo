# Tidal fixtures

Both files are **real responses**, captured with:

```bash
php artisan minizo:tidal:probe ANITTA --save
php artisan minizo:tidal:probe --artist=4906194 --save
```

`artist-search.json` is `GET /v2/searchResults/ANITTA?include=artists.profileArt&countryCode=US`
and `artist-releases.json` is
`GET /v2/artists/4906194/relationships/albums?include=albums.coverArt&limit=10&countryCode=US`.

They were authored from the JSON:API spec first, because Tidal's documentation portal is a
client-rendered SPA and both endpoints reject unauthenticated requests. **That version of the
mapper was wrong in four ways, all of which passed against the authored fixtures**, and the
four are worth recording because each one looked like working code:

| What was assumed | What the API actually does |
|---|---|
| `included` can be read directly | It is ordered **by id**. Searching ANITTA puts Anitta 1st in `data.relationships.artists.data` and 7th in `included` — so reading `included` silently discards relevance, the only ranking the API gives. |
| An artist has an `imageLinks` attribute | An artist has exactly `name`, `popularity`, `externalLinks`, `ownerType`. The picture is a related **`artworks`** resource, needing `?include=artists.profileArt` (`albums.coverArt` for an album), with sizes under `attributes.files`. |
| The first https entry in `externalLinks` is the Tidal page | It also carries Facebook and Twitter. The first entry for one real result was `facebook.com/soueurebeccaa`. The right one has `meta.type === "TIDAL_SHARING"`. |
| `popularity` is a percentage | It is a **0–1 float** (`0.8138…`), so `(int)` reads 0. |

A fifth thing only real data shows: Tidal lists **regional pressings as separate albums**.
This response returns "Goals (FIFA World Cup 2026™)" three times — three ids, three barcodes,
same title, date and duration — and twenty albums collapse to nine once variants are merged
(`TidalRelease::variantKey()`).

## Which tests use these, and which don't

Tests assert against the fixture where the *shape* is the thing under test — the
relationship-vs-`included` ordering, the artwork join, the variant collapse, the real
attribute names.

Where the behaviour is a **Minizo decision** rather than a Tidal shape — the backfill window,
an undated release, a malformed date, the image-URL allow-list — the document is written
inline in the test. Pinning those to whatever the live catalogue happened to contain on the
day of capture would make the test about the wrong thing, and this response has no
out-of-window release at all.

## Re-capturing

Re-run the two commands above. The include parameters in `MinizoTidalProbe` must stay in step
with `TidalCatalogue`'s, or the captured fixture will lack artwork that production requests do
fetch. The probe prints the attribute keys each resource type actually has, so a shape change
shows up as a diff rather than as an empty Feed.

Counts are asserted literally (20 artists, 20 albums → 9 releases), so a re-capture against a
different artist means updating those numbers. That is deliberate: a fixture whose contents
nothing depends on would not catch a mapper that returns an empty list.
