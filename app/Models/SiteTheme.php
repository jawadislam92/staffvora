<?php

namespace App\Models;

use App\Support\HtmlSanitizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * The single, global theme/chrome for the public marketing site. Not
 * tenant-scoped — the landlord owns the website. Everything here is editable
 * from the page builder, so the owner can change the logo, colours, header nav,
 * footer, socials and contact details without touching code.
 *
 * XSS: `nav_links`, `footer_columns` and `social_links` are rendered straight
 * into an `<a href>` by SiteHeader/SiteFooter on EVERY public page, and React 18
 * does not block a `javascript:` href — the production react-dom bundle ships no
 * URL sanitiser at all. So the link trees get the same TWO-PASS treatment
 * SitePage and BlogPost already have:
 *   - on WRITE (setAttribute below), which covers every write path — the builder,
 *     DefaultSiteContent, the seeders, any future import — because it sits on the
 *     model rather than in one controller's validation rules; and
 *   - on READ (publicPayload below), because the write-path fix is NOT
 *     retroactive: a `javascript:` URL that was stored before the guard existed
 *     is still sitting in `site_themes` on the live site, and a row is not
 *     trustworthy merely because it is stored.
 * HtmlSanitizer is idempotent, so the second pass is a no-op on clean content —
 * `/pricing`, `https://…`, `#features`, `mailto:`, `tel:` and a blank field all
 * come back exactly as authored.
 */
class SiteTheme extends Model
{
    /**
     * Link trees whose leaves end up in an href. Walked recursively, so a
     * nesting nobody wrote a validation rule for is still cleaned.
     *
     * @var list<string>
     */
    private const LINK_TREES = ['nav_links', 'footer_columns'];

    protected $guarded = [];

    protected $casts = [
        'nav_links' => 'array',
        'footer_columns' => 'array',
        'social_links' => 'array',
        'default_meta' => 'array',
        'palette' => 'array',
    ];

    /** The one-and-only theme row, created with sensible defaults on first use. */
    public static function current(): self
    {
        return Cache::remember('site_theme', now()->addHours(6), fn () => static::firstOrCreate(['id' => 1]));
    }

    public static function forgetCache(): void
    {
        Cache::forget('site_theme');
    }

    /**
     * Neutralise unsafe hrefs on the way in, whatever the write path.
     *
     * This overrides setAttribute rather than declaring Eloquent mutators so it
     * composes with the `array` casts above instead of competing with them —
     * same reason SitePage does it this way.
     */
    public function setAttribute($key, $value)
    {
        if (in_array($key, self::LINK_TREES, true) && is_array($value)) {
            $value = HtmlSanitizer::sanitizeBlocks($value);
        }

        if ($key === 'social_links' && is_array($value)) {
            $value = self::cleanSocials($value);
        }

        return parent::setAttribute($key, $value);
    }

    /** Public payload shared with every marketing page (header + footer + theme). */
    public function publicPayload(): array
    {
        return [
            'site_name' => $this->site_name ?: 'Staffvora',
            'tagline' => $this->tagline,
            'logo_url' => $this->logo_path ? route('site.media.logo').'?v='.substr(md5($this->logo_path), 0, 8) : null,
            // Raw (nullable) so the public site can tell an owner override from "unset".
            'accent_color' => $this->accent_color,
            'highlight_color' => $this->highlight_color,
            // READ PASS — see the class docblock. The stored row may predate the
            // write guard, so it is cleaned again on the way out.
            'nav_links' => HtmlSanitizer::sanitizeBlocks(self::asArray($this->nav_links)),
            'footer_columns' => HtmlSanitizer::sanitizeBlocks(self::asArray($this->footer_columns)),
            'social_links' => self::cleanSocials(self::asArray($this->social_links)),
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'contact_address' => $this->contact_address,
        ];
    }

    /**
     * `social_links` is a MAP of network => URL (`x`, `linkedin`, …), so the key
     * is never `url` and the recursive walk cannot spot it — the values have to
     * be scheme-checked directly.
     *
     * An unsafe or non-scalar value collapses to '' rather than being dropped,
     * because clearing the field is how the operator overrides a brand default,
     * and SiteFooter already renders nothing for an empty one.
     *
     * @param  array<mixed>  $socials
     * @return array<string, string>
     */
    private static function cleanSocials(array $socials): array
    {
        return array_map(
            static fn (mixed $v): string => HtmlSanitizer::safeLink($v) ?? '',
            $socials
        );
    }

    /** A JSON column can hold a scalar or null; the link walkers need an array. */
    private static function asArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
