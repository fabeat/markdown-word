<?php

declare(strict_types=1);

namespace MarkdownWord\Writer;

use PhpOffice\PhpWord\Settings;

/**
 * PHPWord's output escaping is off by default, and content that has already
 * been escaped is the only thing that survives it: a lone `<` or `&` in raw
 * text — `a < b`, `AT&T` — reaches `w:t` as markup, leaving a part Word cannot
 * open. The setting is static, so the value it was found at is put back.
 *
 * Not {@see \MarkdownWord\Reverse\Escaping}, which escapes Markdown text.
 */
final class OutputEscaping
{
    /**
     * @template T
     * @param callable(): T $write
     * @return T
     */
    public static function enabled(callable $write): mixed
    {
        $previous = Settings::isOutputEscapingEnabled();
        Settings::setOutputEscapingEnabled(true);

        try {
            return $write();
        } finally {
            Settings::setOutputEscapingEnabled($previous);
        }
    }
}
