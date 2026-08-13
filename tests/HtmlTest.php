<?php

declare(strict_types=1);

use Cms\Html;

/**
 * The sanitiser is the only thing standing between authored content and stored
 * XSS, so these tests are adversarial on purpose. Several of them encode
 * bypasses that work against a naive implementation — a case-sensitive scheme
 * check, a check that trims spaces but not tabs, an attribute filter that only
 * looks for "onclick".
 */

return [

    // --- escaping ---------------------------------------------------------

    'escape() neutralises tag delimiters' => function (): void {
        Assert::same(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            Html::escape('<script>alert(1)</script>'),
            'angle brackets must be entity-encoded'
        );
    },

    'escape() covers single quotes as well as double' => function (): void {
        // ENT_QUOTES matters: attributes get written with either quote style,
        // and escaping only double quotes leaves single-quoted ones breakable.
        Assert::same('&#039;', Html::escape("'"), "single quote must be encoded");
        Assert::same('&quot;', Html::escape('"'), 'double quote must be encoded');
    },

    'escape() handles null without warning' => function (): void {
        Assert::same('', Html::escape(null), 'null should become an empty string');
    },

    // --- script injection -------------------------------------------------

    'sanitise() strips script tags' => function (): void {
        $out = Html::sanitise('<p>ok</p><script>alert(1)</script>');
        Assert::missing('<script', $out, 'script tag must not survive');
        Assert::missing('alert(1)', $out, 'script body must not survive');
        Assert::contains('<p>ok</p>', $out, 'legitimate markup must survive');
    },

    'sanitise() strips event handler attributes' => function (): void {
        foreach (['onclick', 'onerror', 'onload', 'onmouseover', 'onfocus'] as $handler) {
            $out = Html::sanitise('<p ' . $handler . '="alert(1)">text</p>');
            Assert::missing($handler, $out, "{$handler} must be stripped");
            Assert::contains('<p>text</p>', $out, 'the paragraph itself must survive');
        }
    },

    'sanitise() strips style attributes' => function (): void {
        $out = Html::sanitise('<p style="background:url(javascript:alert(1))">x</p>');
        Assert::missing('style', $out, 'style attribute must be stripped');
        Assert::missing('javascript', $out, 'the payload inside it must go too');
    },

    'sanitise() strips img entirely, including onerror' => function (): void {
        // img is not on the allow-list — inline images come from the media
        // library through the item's own image field, not from pasted HTML.
        $out = Html::sanitise('<img src=x onerror=alert(1)>');
        Assert::missing('<img', $out, 'img is not allow-listed');
        Assert::missing('onerror', $out, 'and its handler must not leak through as text');
    },

    // --- link schemes -----------------------------------------------------

    'sanitise() removes javascript: hrefs' => function (): void {
        $out = Html::sanitise('<a href="javascript:alert(1)">click</a>');
        Assert::missing('javascript:', $out, 'javascript scheme must not survive');
        Assert::contains('click', $out, 'the link text should remain');
    },

    'sanitise() removes javascript: regardless of case' => function (): void {
        $out = Html::sanitise('<a href="JaVaScRiPt:alert(1)">click</a>');
        Assert::missing('alert(1)', $out, 'scheme check must be case-insensitive');
    },

    'sanitise() removes javascript: padded with control characters' => function (): void {
        // Browsers ignore tabs and newlines inside a scheme; a naive trim() does
        // not, and "java\tscript:" stays live.
        foreach (["java\tscript:alert(1)", "java\nscript:alert(1)", " javascript:alert(1)"] as $payload) {
            $out = Html::sanitise('<a href="' . $payload . '">x</a>');
            Assert::missing('alert(1)', $out, 'padded javascript: must be rejected');
        }
    },

    'sanitise() removes data: and vbscript: hrefs' => function (): void {
        $out = Html::sanitise('<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>');
        Assert::missing('data:', $out, 'data: URIs must be rejected in href');

        $out = Html::sanitise('<a href="vbscript:msgbox(1)">x</a>');
        Assert::missing('vbscript:', $out, 'vbscript: must be rejected');
    },

    'sanitise() keeps ordinary links and adds rel' => function (): void {
        $out = Html::sanitise('<a href="https://example.com">x</a>');
        Assert::contains('href="https://example.com"', $out, 'https links must survive');
        Assert::contains('noopener', $out, 'rel=noopener guards against reverse tabnabbing');
        Assert::contains('noreferrer', $out, 'rel=noreferrer stops the referrer leak');
    },

    'sanitise() keeps relative links and mailto' => function (): void {
        Assert::contains('href="/about"', Html::sanitise('<a href="/about">x</a>'), 'relative links are safe');
        Assert::contains('href="mailto:a@b.co"', Html::sanitise('<a href="mailto:a@b.co">x</a>'), 'mailto is safe');
    },

    'sanitise() drops a link that carries only a target' => function (): void {
        $out = Html::sanitise('<a target="_blank">x</a>');
        Assert::missing('target', $out, 'target must not survive without an href');
    },

    // --- allow-list -------------------------------------------------------

    'sanitise() keeps the documented allow-list' => function (): void {
        $input = '<p>p</p><strong>b</strong><em>i</em><ul><li>l</li></ul>'
               . '<h2>h</h2><blockquote>q</blockquote><code>c</code><pre>pre</pre><hr>';
        $out = Html::sanitise($input);

        foreach (['<p>', '<strong>', '<em>', '<ul>', '<li>', '<h2>', '<blockquote>', '<code>', '<pre>'] as $tag) {
            Assert::contains($tag, $out, "{$tag} is documented as allowed");
        }
    },

    'sanitise() drops tags outside the allow-list' => function (): void {
        foreach (['<iframe src="x">', '<object>', '<embed>', '<form>', '<input>', '<h1>'] as $tag) {
            $out = Html::sanitise($tag . 'content');
            Assert::missing(rtrim(explode(' ', $tag)[0], '>'), $out, "{$tag} must not survive");
        }
    },

    'sanitise() is idempotent' => function (): void {
        // Content is re-sanitised on every save. If a second pass changed the
        // output, editing a post twice would silently corrupt it.
        $input = '<p>Hello <a href="https://example.com">link</a></p>';
        $once  = Html::sanitise($input);
        Assert::same($once, Html::sanitise($once), 'sanitising twice must equal sanitising once');
    },

    // --- safeUrl ----------------------------------------------------------

    'safeUrl() rejects dangerous schemes and allows the rest' => function (): void {
        Assert::same(null, Html::safeUrl('javascript:alert(1)'), 'javascript: is not a safe URL');
        Assert::same(null, Html::safeUrl('  DATA:text/html,x'), 'data: is not safe, even padded and upper-case');
        Assert::same(null, Html::safeUrl('file:///etc/passwd'), 'file: is not safe');
        Assert::same(null, Html::safeUrl(''), 'an empty URL is not usable');
        Assert::same('/about', Html::safeUrl('/about'), 'relative URLs are fine');
        Assert::same('https://x.co', Html::safeUrl('https://x.co'), 'https is fine');
        Assert::same('#section', Html::safeUrl('#section'), 'fragments are fine');
    },

    // --- slug and excerpt -------------------------------------------------

    'slug() produces URL-safe output' => function (): void {
        Assert::same('hello-world', Html::slug('Hello World'), 'spaces become hyphens, case folds');
        Assert::same('a-b', Html::slug('  a & b  '), 'punctuation collapses, edges trim');
        Assert::same('cafe-au-lait', Html::slug('Café au lait!'), 'accented characters are dropped');
    },

    'slug() falls back rather than returning empty' => function (): void {
        // A title in a non-Latin script would otherwise slug to "", and an empty
        // slug makes the item unreachable and collides with every other one.
        $slug = Html::slug('日本語');
        Assert::true($slug !== '', 'a non-Latin title must still produce a usable slug');
        Assert::same($slug, Html::slug('日本語'), 'and that slug must be stable across calls');
    },

    'excerpt() strips markup and truncates on a word boundary' => function (): void {
        $out = Html::excerpt('<p>The quick brown fox jumps over the lazy dog</p>', 20);
        Assert::missing('<p>', $out, 'markup must not appear in a meta description');
        Assert::true(mb_strlen($out) <= 21, 'excerpt must respect its length budget');
        Assert::contains('…', $out, 'a truncated excerpt should be marked as truncated');
    },

    'excerpt() leaves short text alone' => function (): void {
        Assert::same('Short.', Html::excerpt('<p>Short.</p>', 100), 'no ellipsis when nothing was cut');
    },
];
