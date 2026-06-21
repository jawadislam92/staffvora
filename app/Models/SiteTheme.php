<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * The single, global theme/chrome for the public marketing site. Not
 * tenant-scoped — the landlord owns the website. Everything here is editable
 * from the page builder, so the owner can change the logo, colours, header nav,
 * footer, socials and contact details without touching code.
 */
class SiteTheme extends Model
{
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

    /** Public payload shared with every marketing page (header + footer + theme). */
    public function publicPayload(): array
    {
        return [
            'site_name' => $this->site_name ?: 'Tracksuite',
            'tagline' => $this->tagline,
            'logo_url' => $this->logo_path ? route('site.media.logo').'?v='.substr(md5($this->logo_path), 0, 8) : null,
            // Raw (nullable) so the public site can tell an owner override from "unset".
            'accent_color' => $this->accent_color,
            'highlight_color' => $this->highlight_color,
            'nav_links' => $this->nav_links ?? [],
            'footer_columns' => $this->footer_columns ?? [],
            'social_links' => $this->social_links ?? [],
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'contact_address' => $this->contact_address,
        ];
    }
}
