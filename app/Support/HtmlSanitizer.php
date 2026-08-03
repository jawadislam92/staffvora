<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Allow-list HTML sanitiser for operator-authored marketing content.
 *
 * WHY THIS EXISTS
 * ---------------
 * `rich_text` blocks are rendered with `dangerouslySetInnerHTML` on the public
 * marketing site — including /privacy, /terms and /security, which are now
 * ENTIRELY rich_text. Until this class existed there was no sanitiser anywhere
 * in the codebase, despite comments in RichText.jsx and SitePage.php claiming
 * one ran "server-side on save". Anything an operator (or any future import
 * path) put in a block was served verbatim to every anonymous visitor.
 *
 * The builder is platform-admin only, so this is a privileged-user surface
 * rather than an anonymous one — but the blast radius is every visitor on the
 * public site, and a platform-operator account is exactly what a phisher
 * targets. Privileged input is still input.
 *
 * WHY NOT A LIBRARY
 * -----------------
 * composer.json carries no HTML sanitiser (no ezyang/htmlpurifier, no
 * symfony/html-sanitizer, no mews/purifier), and pulling one in mid-round would
 * add a dependency the deploy host has to `composer install` before the fix is
 * live — i.e. the vulnerability stays open until an unrelated ops step happens.
 * ext-dom is already required and present, the allow-list here is small and
 * closed, and the semantics are the conservative ones (unknown element =>
 * unwrapped, unknown attribute => dropped, unknown URL scheme => dropped). If a
 * richer content model is ever needed, swapping the internals of clean() for
 * symfony/html-sanitizer is a contained change — every caller goes through
 * this class.
 *
 * MODEL
 * -----
 *  - Elements not in ALLOWED are UNWRAPPED (children kept) so a stray <div> or
 *    <font> degrades to its text rather than silently eating a paragraph…
 *  - …except DROP_WITH_CONTENT, which is removed entirely, because the content
 *    of a <script>/<style>/<iframe> IS the payload.
 *  - Attributes are per-tag allow-listed. `style`, `class`, `id`, every `on*`
 *    handler and everything else is dropped — that kills style-based injection
 *    (`expression()`, `-moz-binding`, positioned overlays) as a category
 *    instead of trying to parse CSS.
 *  - URL attributes must resolve to http/https/mailto/tel or a same-site
 *    relative path. `javascript:`, `data:`, `vbscript:` and obfuscations of
 *    them (control characters, HTML entities, mixed case) are dropped.
 *  - Comments are dropped (conditional comments are a script vector).
 *
 * IDEMPOTENT: clean(clean($x)) === clean($x). This matters because the same
 * markup is sanitised on save AND again on render; the legal pages must survive
 * both passes byte-identical, which SiteContentSanitizationTest asserts against
 * the real seeder content.
 */
final class HtmlSanitizer
{
    /**
     * tag => allowed attributes. Everything a rich-text editor realistically
     * emits and the marketing/legal content actually uses; nothing that can
     * load or execute code.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        'p' => [],
        'br' => [],
        'hr' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        's' => [],
        'h2' => [],
        'h3' => [],
        'h4' => [],
        'h5' => [],
        'h6' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'blockquote' => [],
        'code' => [],
        'pre' => [],
        'span' => [],
        'small' => [],
        'sub' => [],
        'sup' => [],
        'figure' => [],
        'figcaption' => [],
        'table' => [],
        'thead' => [],
        'tbody' => [],
        'tfoot' => [],
        'tr' => [],
        'th' => [],
        'td' => [],
        'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'width', 'height'],
    ];

    /**
     * Elements removed together with everything inside them. For these the
     * children are the attack, so unwrapping would be the bug.
     *
     * @var list<string>
     */
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'applet', 'frame',
        'frameset', 'noscript', 'noframes', 'template', 'link', 'meta', 'base',
        'title', 'head', 'form', 'input', 'button', 'select', 'option',
        'optgroup', 'textarea', 'label', 'fieldset', 'legend', 'output',
        'svg', 'math', 'canvas', 'audio', 'video', 'source', 'track',
        'portal', 'xml', 'plaintext',
    ];

    /** Attributes carrying a URL, which get scheme-checked rather than passed through. */
    private const URL_ATTRIBUTES = ['href', 'src'];

    /** URL schemes that may appear in href/src. */
    private const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /** Elements serialised without a closing tag; used to keep output stable. */
    private const VOID = ['br', 'hr', 'img'];

    /**
     * Sanitise one HTML fragment. Returns '' for empty/unparseable input —
     * failing closed, because "could not parse it" is never a reason to serve
     * it raw.
     */
    public static function clean(?string $html): string
    {
        $html = (string) $html;

        if (trim($html) === '') {
            return '';
        }

        // Hard cap. The builder is trusted-ish, but an accidental paste of a
        // whole saved web page should not turn every public page render into a
        // multi-megabyte DOM parse.
        if (strlen($html) > 512_000) {
            $html = substr($html, 0, 512_000);
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        // The XML processing instruction is what tells libxml the bytes are
        // UTF-8 (mb_convert_encoding to HTML-ENTITIES is deprecated in 8.2+).
        // The wrapper <div> gives a single documentElement even when the
        // fragment has many top-level nodes.
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8" ?><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return '';
        }

        $root = null;
        foreach ($doc->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $root = $child;
                break;
            }
        }

        if (! $root) {
            return '';
        }

        self::scrub($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= self::serialize($doc, $child);
        }

        return $out;
    }

    /**
     * Recursively sanitise `html` values anywhere in a block tree.
     *
     * Only keys literally named `html` are treated as markup — every other
     * string is rendered as a React child (escaped by React) and running it
     * through the DOM would corrupt legitimate text such as `Q&A` or `5 < 6`.
     *
     * @param  array<mixed>  $blocks
     * @return array<mixed>
     */
    public static function sanitizeBlocks(array $blocks): array
    {
        foreach ($blocks as $key => $value) {
            if (is_array($value)) {
                $blocks[$key] = self::sanitizeBlocks($value);

                continue;
            }

            if ($key === 'html' && is_string($value)) {
                $blocks[$key] = self::clean($value);
            }
        }

        return $blocks;
    }

    /** Depth-first scrub of an element's children, in place. */
    private static function scrub(DOMElement $element): void
    {
        // Snapshot: the live NodeList shifts underneath us as nodes are removed.
        $children = iterator_to_array($element->childNodes);

        foreach ($children as $child) {
            if ($child instanceof \DOMComment) {
                $child->parentNode?->removeChild($child);

                continue;
            }

            if ($child instanceof \DOMProcessingInstruction) {
                $child->parentNode?->removeChild($child);

                continue;
            }

            if (! $child instanceof DOMElement) {
                // Text / CDATA — kept, and escaped on serialisation.
                continue;
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $child->parentNode?->removeChild($child);

                continue;
            }

            if (! array_key_exists($tag, self::ALLOWED)) {
                // Unknown but harmless-by-itself element: scrub its subtree,
                // then unwrap it so the text survives.
                self::scrub($child);
                self::unwrap($child);

                continue;
            }

            self::filterAttributes($child, $tag);
            self::scrub($child);
        }
    }

    /** Replace an element with its children. */
    private static function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if (! $parent) {
            return;
        }

        while ($element->firstChild) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    private static function filterAttributes(DOMElement $element, string $tag): void
    {
        $allowed = self::ALLOWED[$tag];

        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->nodeName);

            if (! in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            if (in_array($name, self::URL_ATTRIBUTES, true)) {
                $safe = self::safeUrl($attribute->nodeValue ?? '');

                if ($safe === null) {
                    $element->removeAttribute($attribute->nodeName);

                    continue;
                }

                $element->setAttribute($name, $safe);
            }
        }

        if ($tag === 'a') {
            // An <a> whose href was rejected is a dead link, but leaving it is
            // harmless and keeps the surrounding text. Only the target/rel pair
            // needs correcting.
            if ($element->getAttribute('target') !== '') {
                $element->setAttribute('target', '_blank');
                $element->setAttribute('rel', 'noopener noreferrer');
            }
        }

        if ($tag === 'img' && $element->getAttribute('src') === '') {
            // An <img> with no usable source is noise; drop the element but
            // keep nothing (images have no meaningful children).
            $element->parentNode?->removeChild($element);
        }
    }

    /**
     * Return the URL if it is safe to emit, or null.
     *
     * The scheme test runs against a normalised probe with control characters,
     * whitespace and HTML/numeric entities stripped, so `java&#09;script:`,
     * `JaVaScRiPt:` and `  javascript:` are all caught. Relative URLs and
     * fragments pass through untouched.
     */
    private static function safeUrl(string $url): ?string
    {
        $value = trim($url);

        if ($value === '') {
            return null;
        }

        $probe = strtolower($value);
        // Entities first (they can encode the control characters), then the
        // control characters themselves.
        $probe = preg_replace('/&#x?[0-9a-f]+;?/i', '', $probe) ?? $probe;
        $probe = preg_replace('/&(?:tab|newline|colon);/i', '', $probe) ?? $probe;
        $probe = preg_replace('/[\x00-\x20\x7f]/', '', $probe) ?? $probe;

        if ($probe === '') {
            return null;
        }

        if (str_starts_with($probe, '#') || str_starts_with($probe, '/')) {
            return $value;
        }

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $probe, $matches)) {
            return in_array($matches[1], self::SAFE_SCHEMES, true) ? $value : null;
        }

        // No scheme and not root-relative: a relative path like `pricing` or
        // `docs/install.html`. A colon later in the string cannot form a scheme
        // once a `/` or `?` has appeared first, and the check above already
        // covers the case where it could.
        return $value;
    }

    /** Serialise a node with HTML (not XML) void-element semantics. */
    private static function serialize(DOMDocument $doc, DOMNode $node): string
    {
        if ($node instanceof DOMElement && in_array(strtolower($node->nodeName), self::VOID, true)) {
            // saveHTML already emits `<br>` rather than `<br/>`; this is here so
            // a void element can never gain a bogus closing tag if the node was
            // built by hand.
            return $doc->saveHTML($node);
        }

        return $doc->saveHTML($node);
    }
}
