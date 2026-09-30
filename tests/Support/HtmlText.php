<?php

declare(strict_types=1);

namespace MarkdownWord\Tests\Support;

/**
 * Extracts the visible text of an HTML fragment the way a browser would render
 * it, which is what the specification's expected output describes.
 */
final class HtmlText
{
    private const BLOCK_TAGS = [
        'address', 'article', 'aside', 'blockquote', 'div', 'dd', 'dl', 'dt',
        'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3',
        'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre',
        'section', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
    ];

    private const VOID_TAGS = ['br', 'hr', 'img', 'input', 'meta', 'link', 'source', 'col', 'area', 'base', 'embed', 'track', 'wbr'];

    public static function extract(string $html): string
    {
        $html = self::stripComments($html);

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);

        $loaded = $dom->loadHTML(
            '<?xml encoding="utf-8" ?><body>' . $html . '</body>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new \RuntimeException('The expected HTML could not be parsed.');
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }

        return trim(self::walk($body, false));
    }

    /**
     * Remove comments before parsing.
     *
     * `<!-->` and `<!--->` are comments in HTML — the "abrupt closing" forms the
     * specification allows — but libxml does not recognise them and swallows the
     * rest of the document instead. Stripping comments first sidesteps that, and
     * is harmless because a comment contributes no text either way.
     */
    private static function stripComments(string $html): string
    {
        // The order the alternatives are tried in is the point of the grouping:
        // the abrupt-closing forms, then a real comment, then an unterminated one,
        // which has to swallow the rest of the input. The `/s` flag is what lets
        // `.` reach the end of it.
        return (string) preg_replace('/(<!--(?:->|>|.*?-->|.*$))/s', '', $html);
    }

    private static function walk(\DOMNode $node, bool $preserveWhitespace): string
    {
        $text = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                // A newline in HTML source is a soft break, which a browser shows
                // as a space; `pre` and `textarea` keep theirs, tabs included —
                // a tab is a tab stop rather than a character.
                $text .= $preserveWhitespace
                    ? $child->textContent
                    : (string) preg_replace('/\s+/', ' ', $child->textContent);
                continue;
            }

            if ($child instanceof \DOMComment) {
                continue;
            }

            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            // An image renders as itself, so it contributes no text; neither do
            // the other void elements.
            if (in_array($tag, self::VOID_TAGS, true)) {
                if ($tag === 'br') {
                    $text .= "\n";
                }
                continue;
            }

            $verbatim = $tag === 'pre' || $tag === 'textarea';
            $inner = self::walk($child, $preserveWhitespace || $verbatim);

            // The source of a `pre` block ends with a newline that carries no
            // content, so it is removed here rather than compared; so is the
            // blank line a browser drops after the `textarea` start tag.
            if ($verbatim) {
                $inner = trim($inner, "\n");
            }

            if ($tag === 'td' || $tag === 'th') {
                $text .= ($text === '' || str_ends_with($text, "\n") ? '' : ' ') . trim($inner) . "\t";
                continue;
            }

            $text .= in_array($tag, self::BLOCK_TAGS, true) ? "\n" . $inner . "\n" : $inner;
        }

        return $text;
    }
}
