import fs from 'node:fs';
import path from 'node:path';
import {
    defineConfig
} from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from "@tailwindcss/vite";

/*
 * Drop the .woff faces the font pipeline emits alongside the .woff2 ones.
 *
 * Bunny serves one @font-face per weight carrying a format-fallback list:
 *
 *     src: url(...woff2) format('woff2'), url(...woff) format('woff');
 *
 * which is correct - a browser takes the first format it supports and ignores the
 * rest. laravel-vite-plugin splits that list into one @font-face RULE per format, so
 * the output holds two rules with the same family, weight, style and unicode-range.
 * That is not a fallback, it is a duplicate, and browsers fetch both: measured, every
 * weight downloaded its .woff2 AND its .woff.
 *
 * woff2 has universal support in anything that can run this app, so the .woff half is
 * pure waste - 147 KB of it. The plugin exposes no way to ask for one format, hence
 * this pass. Remove it if the upstream splitting is ever fixed.
 */
function woff2Only() {
    const dropWoffFaces = (css) => css
        .replace(/@font-face\s*\{[^}]*format\(["']woff["']\)[^}]*\}\s*/g, '')
        .trim();

    return {
        name: 'minizo:woff2-only',
        apply: 'build',
        closeBundle() {
            const buildDir = path.resolve('public/build');
            const manifestPath = path.join(buildDir, 'fonts-manifest.json');

            if (! fs.existsSync(manifestPath)) {
                return;
            }

            const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));

            // The manifest is what matters most: @fonts inlines these strings into a
            // <style> block rather than linking the stylesheet.
            for (const [alias, css] of Object.entries(manifest.style?.familyStyles ?? {})) {
                manifest.style.familyStyles[alias] = dropWoffFaces(css);
            }

            fs.writeFileSync(manifestPath, JSON.stringify(manifest, null, 2));

            // The emitted stylesheet, for anything that links it directly.
            const cssFile = manifest.style?.file
                ? path.join(buildDir, manifest.style.file)
                : null;

            if (cssFile && fs.existsSync(cssFile)) {
                fs.writeFileSync(cssFile, dropWoffFaces(fs.readFileSync(cssFile, 'utf8')));
            }

            // The files themselves, now that nothing references them.
            const assets = path.join(buildDir, 'assets');
            let removed = 0;

            if (fs.existsSync(assets)) {
                for (const file of fs.readdirSync(assets)) {
                    if (file.endsWith('.woff')) {
                        fs.unlinkSync(path.join(assets, file));
                        removed++;
                    }
                }
            }

            if (removed > 0) {
                this.info(`minizo: dropped ${removed} duplicate .woff font files`);
            }
        },
    };
}

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/passkeys.js',
            ],
            refresh: true,
            /*
             * Manrope for UI, JetBrains Mono for every machine value (sizes,
             * durations, timestamps, tokens, URLs, format badges, release IDs).
             * That mono/sans split is the most identity-defining rule in the
             * design handoff.
             *
             * Weights are exactly what the prototype uses - 800 carries all the
             * uppercase section headers, so it is not optional.
             *
             * If you change these, `--font-sans` / `--font-mono` in
             * resources/css/app.css must change too. Both files, always.
             *
             * `preload` is a shorter list than `weights`, and on purpose. A preload
             * hint fetches the file at highest priority on every page whether that
             * page uses the weight or not, competing with the stylesheet that
             * actually blocks render. All seven cost 113 KB up front.
             *
             * Preloaded: the three that carry visible text on first paint - 400 body,
             * 600 for labels and buttons, 800 for the uppercase section headers -
             * plus mono 400 for the machine values in every table. The rest still
             * load, just on demand when a rule matches them.
             */
            fonts: [
                bunny('Manrope', {
                    weights: [400, 500, 600, 700, 800],
                    preload: [
                        { weight: 400 },
                        { weight: 600 },
                        { weight: 800 },
                    ],
                }),
                bunny('JetBrains Mono', {
                    weights: [400, 600],
                    preload: [
                        { weight: 400 },
                    ],
                }),
            ],
        }),
        tailwindcss(),
        woff2Only(),
    ],
    server: {
        cors: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
