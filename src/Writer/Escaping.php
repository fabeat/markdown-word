<?php

declare(strict_types=1);

namespace MarkdownWord\Writer;

use PhpOffice\PhpWord\Settings;

/**
 * Runs a callback with PHPWord's output escaping enabled.
 *
 * Escaping is off by default in PHPWord, which is correct for content that has
 * already been escaped and wrong for everything else: a document containing a
 * lone `<` or `&` — `a < b`, `AT&T`, `&amp;` — then writes raw markup into
 * `w:t` and produces XML that Word refuses to open.
 *
 * The previous value is restored afterwards so the host application's own
 * settings are left as they were found.
 */
final class Escaping
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
