<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Serves the dashboard's hand-written CSS/JS as static files.
 *
 * Deliberately outside the `viewRanetrace` gate: the assets carry no secrets
 * (they only show installation state, never captured data), so serving them
 * publicly keeps the page CSP-clean — no inline `<style>`/`<script>`. All
 * *data* routes stay behind the gate.
 *
 * Cache-busting is by content hash (see version()): the shell appends `?v=<hash>`
 * to each asset URL, so a far-future, immutable Cache-Control header is safe.
 */
class AssetController extends Controller
{
    /**
     * Directory holding the package's hand-written dashboard assets.
     */
    protected const string ASSET_DIR = __DIR__.'/../../../resources/dashboard/';

    /**
     * Short content hash of both assets, used by the shell for cache-busting.
     * Degrades to a stable fallback if a file is unreadable so the page still
     * renders (the assets just won't be re-fetched until they reappear).
     */
    public static function version(): string
    {
        $material = '';
        foreach (['ranetrace.css', 'ranetrace.js'] as $file) {
            $path = self::ASSET_DIR.$file;
            $material .= is_file($path) ? (string) md5_file($path) : '';
        }

        return mb_substr(md5($material !== '' ? $material : 'ranetrace'), 0, 12);
    }

    public function css(): Response
    {
        return $this->serve('ranetrace.css', 'text/css; charset=UTF-8');
    }

    public function js(): Response
    {
        return $this->serve('ranetrace.js', 'application/javascript; charset=UTF-8');
    }

    protected function serve(string $file, string $contentType): Response
    {
        $path = self::ASSET_DIR.$file;

        if (! is_file($path) || ($contents = file_get_contents($path)) === false) {
            abort(404);
        }

        return response($contents, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
