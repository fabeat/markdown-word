<?php

declare(strict_types=1);

namespace MarkdownWord\Console;

use function str_contains;
use function str_ends_with;
use function str_replace;

/**
 * Keeps one known defect in a dependency off the terminal.
 *
 * PHPWord 1.4 — the latest release — reads its style registry with a null array
 * offset while writing a paragraph that carries no numbering of its own, which
 * every list item does:
 *
 * ```php
 * if (isset(self::$styles[$styleName])) {   // $styleName is null
 * ```
 *
 * PHP reports each occurrence itself, so a document with a dozen list items
 * prints a dozen paragraphs of someone else's warning before the tool says
 * anything at all. On a command line that is unusable output.
 *
 * The library itself does not do this: a caller embedding it in an application
 * is better served by seeing everything, and by this being a property of the
 * program they chose to run rather than of a converter they did not. Only the
 * one message from the one file is swallowed; a deprecation from anywhere else,
 * including from this library, is passed straight through.
 */
final class UpstreamDeprecations
{
    private const NULL_OFFSET = 'Using null as an array offset';

    private const PHPWORD_STYLE = '/phpoffice/phpword/src/PhpWord/Style.php';

    private static bool $installed = false;

    /**
     * Put the filter in place. Calling it twice is a no-op.
     */
    public static function install(): void
    {
        if (self::$installed) {
            return;
        }

        self::$installed = true;

        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
            if ($severity === E_DEPRECATED && self::isKnown($message, $file)) {
                return true;
            }

            // False hands the diagnostic to PHP, which prints it and carries on —
            // exactly what would have happened without this filter.
            return false;
        });
    }

    /**
     * Take the filter off again, leaving the previous handler at the stack depth
     * it had before.
     */
    public static function restore(): void
    {
        if (!self::$installed) {
            return;
        }

        self::$installed = false;
        restore_error_handler();
    }

    /**
     * Run a closure with the filter in place.
     */
    public static function quietly(callable $work): mixed
    {
        $installed = !self::$installed;
        self::install();

        try {
            return $work();
        } finally {
            if ($installed) {
                self::restore();
            }
        }
    }

    private static function isKnown(string $message, string $file): bool
    {
        return str_contains($message, self::NULL_OFFSET)
            && str_ends_with(str_replace('\\', '/', $file), self::PHPWORD_STYLE);
    }
}
