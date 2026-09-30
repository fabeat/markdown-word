<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

/**
 * The token that reserves a spot in the element tree for a hyperlink whose label
 * contains emphasis; {@see \MarkdownWord\Render\LinkPayloadCollector} records it
 * as the payload's `placeholder` and {@see \MarkdownWord\Writer\HyperlinkPass}
 * swaps it for a real `w:hyperlink`.
 *
 * U+2063 INVISIBLE SEPARATOR brackets each end, so it cannot collide with
 * anything an author typed, and no part of it is XML whitespace, which keeps the
 * whole token inside the one `w:t` the pass matches against.
 */
final class LinkPlaceholder
{
    public const MARKER = "\u{2063}MDWL\u{2063}";

    public static function forIndex(int $index): string
    {
        return self::MARKER . $index . self::MARKER;
    }
}
