<?php

namespace App\Support;

use App\Models\SitePage;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves config/navigation.php into the header + footer the marketing site
 * actually renders (SITE_BUILD_SPEC §2.3). An item is dropped when:
 *   - it has a `flag` whose config/features.php value is false, or
 *   - it has a `page` slug that isn't a published site_page.
 * Empty parents (a dropdown / footer column with nothing left) are dropped too.
 *
 * This is why reserved `planned` pages and flagged-off sections never leak into
 * nav: turn one on and it appears automatically — no JSX edit.
 */
class SiteNavigation
{
    public static function resolve(): array
    {
        $config = config('navigation', ['header' => [], 'footer' => []]);
        $published = self::publishedSlugs();

        return [
            'header' => self::filterHeader($config['header'] ?? [], $published),
            'footer' => self::filterFooter($config['footer'] ?? [], $published),
        ];
    }

    /** Published page slugs, cached briefly (busted whenever a page publishes). */
    private static function publishedSlugs(): array
    {
        return Cache::remember('site_published_slugs', now()->addHours(6), function () {
            return SitePage::where('status', 'published')->pluck('slug')->all();
        });
    }

    public static function forgetCache(): void
    {
        Cache::forget('site_published_slugs');
    }

    private static function visible(array $item, array $published): bool
    {
        if (isset($item['flag']) && ! config('features.'.$item['flag'], false)) {
            return false;
        }
        if (isset($item['page']) && ! in_array($item['page'], $published, true)) {
            return false;
        }

        return true;
    }

    private static function filterHeader(array $items, array $published): array
    {
        $out = [];
        foreach ($items as $item) {
            if (! self::visible($item, $published)) {
                continue;
            }
            if (! empty($item['children'])) {
                $children = array_values(array_filter($item['children'], fn ($c) => self::visible($c, $published)));
                if (! $children) {
                    continue; // dropdown with nothing left → drop the parent
                }
                $item['children'] = array_map(fn ($c) => self::link($c), $children);
            }
            $out[] = self::link($item, ! empty($item['children']) ? $item['children'] : null);
        }

        return $out;
    }

    private static function filterFooter(array $columns, array $published): array
    {
        $out = [];
        foreach ($columns as $col) {
            if (isset($col['flag']) && ! config('features.'.$col['flag'], false)) {
                continue; // whole column flagged off
            }
            $links = array_values(array_filter($col['links'] ?? [], fn ($l) => self::visible($l, $published)));
            if (! $links) {
                continue;
            }
            $out[] = [
                'title' => $col['title'] ?? '',
                'links' => array_map(fn ($l) => self::link($l), $links),
            ];
        }

        return $out;
    }

    /** Strip server-only keys (flag/page) — the client only needs label/url/children. */
    private static function link(array $item, ?array $children = null): array
    {
        $out = ['label' => $item['label'] ?? '', 'url' => $item['url'] ?? '#'];
        if ($children !== null) {
            $out['children'] = $children;
        }

        return $out;
    }
}
