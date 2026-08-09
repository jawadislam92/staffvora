@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
{{-- Text wordmark, not an image: Outlook's Word engine is unreliable with
     remote images (many corporate clients block them by default, so an
     image-only logo would render as a blank box on the very first email a
     new hire sees). A styled text lockup always renders. --}}
<span class="logo-mark">{{ config('brand.name', config('app.name')) }}</span><span class="logo-dot">&nbsp;&bull;</span>
</a>
</td>
</tr>
