<?php

declare(strict_types=1);

namespace MarkdownWord\Writer;

use PhpOffice\PhpWord\Settings;

/**
 * Runs a callback with PHPWord's output escaping enabled.
 *
 * Escaping is off by default in PHPWord, which is right only for content that
 * has already been escaped: a document holding a lone `<` or `&` — `a < b`,
 * `AT&T`, `&amp;` — otherwise writes raw markup into `w:t` and Word refuses to
 * open the result. The previous setting is restored afterwards so the host
 * application's own settings are left as they were found.
 *
 * Nothing to do with {@see \MarkdownWord\Reverse\Escaping}, which is about
 * escaping Markdown text.
 */
final class OutputEscaping
{
    /**
     * @template T
     *
     * @param callable(): T $write
     *
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
