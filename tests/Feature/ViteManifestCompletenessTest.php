<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Every Inertia page must have an entry in the committed Vite manifest.
 *
 * This is not theoretical: Timesheets/Index.jsx once vanished from the
 * manifest because it and its sibling Show.jsx both imported lucide's
 * PenLine, and Rollup folded the Index chunk into Show's — so the BUILT
 * site 500'd on /timesheets ("Unable to locate file in Vite manifest")
 * while dev mode, which never reads the manifest, worked perfectly. Every
 * JS test passed; only visiting the built page found it.
 *
 * public/build is committed and deployed as-is in this repo, so the
 * manifest in the tree IS the manifest production serves — which makes it
 * testable here. If this test fails after you add a page, run
 * `npm run build`; if it fails after only an ICON change, you have hit the
 * chunk-merge bug above — change which icon you import (see the comment in
 * Timesheets/Index.jsx).
 */
class ViteManifestCompletenessTest extends TestCase
{
    public function test_every_inertia_page_has_a_vite_manifest_entry(): void
    {
        $manifestPath = public_path('build/manifest.json');

        $this->assertFileExists($manifestPath, 'public/build/manifest.json is missing — run npm run build.');

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $this->assertIsArray($manifest, 'public/build/manifest.json is not valid JSON.');

        $pagesDir = resource_path('js/Pages');
        $pages = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pagesDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'jsx') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            $pages[] = $relative;
        }

        $this->assertNotEmpty($pages, 'No pages found under resources/js/Pages — the scan is broken.');

        $missing = array_values(array_filter($pages, fn ($p) => ! array_key_exists($p, $manifest)));

        $this->assertSame(
            [],
            $missing,
            "These pages have no Vite manifest entry, so the BUILT site 500s the moment they are visited\n"
            ."(dev mode hides this — it never reads the manifest):\n - "
            .implode("\n - ", $missing)
            ."\n\nIf you just added a page: run npm run build. If you only changed lucide icon imports:\n"
            ."you have hit the Rollup chunk-merge bug documented in this test's docblock."
        );
    }
}
