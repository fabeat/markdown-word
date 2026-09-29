<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

/**
 * The token used to reserve a spot in the element tree for a hyperlink whose
 * label contains emphasis.
 *
 * It is wrapped in U+2063 INVISIBLE SEPARATOR characters so that it can never
 * collide with anything an author typed, and so that even if the writer pass
 * were skipped the run would render as nothing visible rather than as noise.
 */
final class LinkPlaceholder
{
    public const MARKER = "\u{2063}MDWL\u{2063}";

    public static function forIndex(int $index): string
    {
        return self::MARKER . $index . self::MARKER;
    }
}
