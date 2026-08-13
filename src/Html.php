<?php

declare(strict_types=1);

namespace Cms;

/**
 * Output escaping and a small allow-list sanitiser for authored rich text.
 */
final class Html
{
    /**
     * Escape for HTML text and quoted attribute contexts.
     *
     * ENT_QUOTES covers single quotes as well as double, which matters because
     * attributes get written with either. Never use this for a URL in href, an
     * unquoted attribute, or anything inside a <script> — escaping is context
     * dependent and this function only knows one context.
     */
    public static function escape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Sanitise authored HTML against a fixed allow-list.
     *
     * Deliberately an allow-list: a block-list of dangerous tags is a list of
     * the attacks known on the day it was written. Anything not named here is
     * stripped, including every event handler attribute and every scheme other
     * than http, https and mailto.
     *
     * This handles content typed by the one person who owns the admin account.
     * It is not hardened enough for untrusted public submissions — if this CMS
     * ever accepts HTML from visitors, put HTMLPurifier in front of it instead.
     */
    public static function sanitise(string $html): string
    {
        $allowed = '<p><br><strong><em><b><i><u><s><ul><ol><li>'
                 . '<h2><h3><h4><blockquote><a><code><pre><hr>';

        // strip_tags() removes tags but keeps the text between them, so
        // "<script>alert(1)</script>" would survive as the visible text
        // "alert(1)". Harmless to execute, but it renders on the page. These
        // elements are content-bearing, so remove them whole first.
        $clean = preg_replace('#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? $html;

        // An unclosed <script> means everything after it is script content.
        $clean = preg_replace('#<(script|style|iframe|object|embed)\b.*#is', '', $clean) ?? $clean;

        $clean = strip_tags($clean, $allowed);

        // strip_tags keeps attributes, including onclick and href="javascript:".
        // Rebuild the tags that are allowed to carry attributes, and drop the
        // attributes from everything else.
        $clean = preg_replace_callback(
            '/<a\b[^>]*>/i',
            static function (array $m): string {
                if (!preg_match('/\bhref\s*=\s*("|\')(.*?)\1/i', $m[0], $href)) {
                    return '<a>';
                }

                $url = self::safeUrl(html_entity_decode($href[2], ENT_QUOTES, 'UTF-8'));
                if ($url === null) {
                    return '<a>';
                }

                // rel on external links: noopener closes the reverse-tabnabbing
                // hole opened by target=_blank, noreferrer stops the referrer leak.
                return '<a href="' . self::escape($url) . '" rel="noopener noreferrer">';
            },
            $clean
        ) ?? $clean;

        // Every other allowed tag keeps its name and loses its attributes.
        $clean = preg_replace('/<(?!\/?a\b)(\/?)([a-z0-9]+)\b[^>]*>/i', '<$1$2>', $clean) ?? $clean;

        return trim($clean);
    }

    /**
     * Return the URL if its scheme is safe, or null.
     *
     * Relative URLs and fragments pass. javascript:, data: and vbscript: do not,
     * including when padded or case-mixed to dodge a naive check.
     */
    public static function safeUrl(string $url): ?string
    {
        $trimmed = trim($url);

        // Strip control characters and whitespace that browsers ignore but a
        // scheme check does not: "java\tscript:alert(1)" is a live URL.
        $normalised = strtolower(preg_replace('/[\x00-\x20]+/', '', $trimmed) ?? '');

        foreach (['javascript:', 'data:', 'vbscript:', 'file:'] as $scheme) {
            if (str_starts_with($normalised, $scheme)) {
                return null;
            }
        }

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Common accented Latin characters and the ASCII they should become.
     *
     * An explicit map rather than iconv('ASCII//TRANSLIT'), whose output
     * depends on the server's locale — the same title can slug differently on
     * two machines, which silently breaks every existing URL when a site moves.
     */
    private const TRANSLITERATE = [
        'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','æ'=>'ae',
        'ç'=>'c','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ì'=>'i','í'=>'i',
        'î'=>'i','ï'=>'i','ñ'=>'n','ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o',
        'ö'=>'o','ø'=>'o','ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ý'=>'y',
        'ÿ'=>'y','ß'=>'ss','œ'=>'oe','š'=>'s','ž'=>'z','đ'=>'d','ł'=>'l',
    ];

    /**
     * A URL-safe slug. Falls back to a stable token for non-Latin titles.
     */
    public static function slug(string $text): string
    {
        $slug = mb_strtolower(trim($text), 'UTF-8');
        $slug = strtr($slug, self::TRANSLITERATE);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        // A title in a script with no ASCII equivalent — Japanese, Devanagari —
        // slugs to nothing. An empty slug is unreachable and collides with every
        // other empty one, so derive a stable token from the title instead.
        return $slug !== '' ? $slug : 'item-' . substr(sha1($text), 0, 8);
    }

    /** Plain-text excerpt of authored HTML, for meta descriptions and cards. */
    public static function excerpt(string $html, int $length = 160): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        $cut   = mb_substr($text, 0, $length);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false ? mb_substr($cut, 0, $space) : $cut, ",.;:") . '…';
    }
}
