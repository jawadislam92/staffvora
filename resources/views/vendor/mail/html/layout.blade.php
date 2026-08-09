<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ config('brand.name', config('app.name')) }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
{{-- "light dark" (not just "light") is what lets Apple Mail / other clients
     that honour color-scheme actually switch palettes via the theme CSS's
     prefers-color-scheme block below. The theme's light palette is still a
     fully standalone design — most clients (Outlook included) ignore this
     hint entirely and just render the light HTML/CSS as authored. --}}
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<style>
@media only screen and (max-width: 600px) {
.inner-body {
width: 100% !important;
}

.footer {
width: 100% !important;
}
}

@media only screen and (max-width: 500px) {
.button {
width: 100% !important;
}
}

/*
 * Dark mode — best-effort only, for the minority of clients (Apple Mail,
 * some Outlook builds on iOS/macOS) that honour prefers-color-scheme. Every
 * other client, Outlook's desktop Word engine included, ignores this block
 * completely and renders the light theme (html/themes/staffvora.css) as
 * authored, which is why that theme is a fully standalone design on its own.
 *
 * THIS BLOCK MUST LIVE HERE, NOT IN THE THEME .CSS FILE: Laravel's Markdown
 * mailer inlines the theme CSS via tijsverkoyen/css-to-inline-styles, whose
 * Css\Processor::doCleanup() unconditionally regex-strips every media-query
 * rule before inlining (see the top-of-file warning in html/themes/
 * staffvora.css for exactly why that regex is dangerous to even mention by
 * name in a comment) — one placed in the theme file is silently deleted and
 * never reaches the sent email. A literal <style> tag already present in the
 * HTML (this one) is untouched by that step, so it is the only place a media
 * query survives.
 */
@media (prefers-color-scheme: dark) {
body,
.wrapper,
.body {
background-color: #0E1520 !important;
color: #CBD5E1 !important;
}

.inner-body {
background-color: #161F2E !important;
border-color: #263041 !important;
}

p,
.table td,
.panel-content,
.panel-content p {
color: #CBD5E1 !important;
}

h1, h2, h3, .table th {
color: #F1F5F9 !important;
}

.header a,
.logo-mark {
color: #F1F5F9 !important;
}

.logo-dot {
color: #A78BFA !important;
}

a {
color: #60A5FA !important;
}

/* Button links are still `<a>` tags, so the rule above would otherwise
   repaint their text too (blue-on-blue instead of white-on-blue). A class
   selector out-specifies the bare tag selector, so this wins regardless of
   source order. */
.button,
a.button {
color: #fff !important;
}

.panel {
border-left-color: #3B82F6 !important;
}

.panel-content {
background-color: #1B2536 !important;
}

.subcopy {
border-top-color: #263041 !important;
}

.subcopy p,
.footer p,
p.sub {
color: #8B98AC !important;
}

.footer a {
color: #8B98AC !important;
}

.transactional-note {
color: #5D6B80 !important;
}

.button-blue,
.button-primary {
background-color: #3B82F6 !important;
border-color: #3B82F6 !important;
}
}
</style>
{!! $head ?? '' !!}
</head>
<body>

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<!-- Body content -->
<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
