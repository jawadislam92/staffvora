<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An image uploaded in the page builder, stored on the S3-backed `avatars`
 * disk and served by id through a cached public route.
 */
class SiteMedia extends Model
{
    protected $table = 'site_media';

    protected $guarded = [];

    public function url(): string
    {
        return route('site.media', $this).'?v='.substr(md5($this->path), 0, 8);
    }
}
