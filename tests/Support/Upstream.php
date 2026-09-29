<?php

declare(strict_types=1);

namespace MarkdownWord\Tests\Support;

/**
 * Hides one known defect in a dependency.
 *
 * PHPWord 1.4 — the latest release — reads its style registry with a null array
 * offset while writing a paragraph that carries no numbering of its own, which
 * every list item does:
 *
 * ```php
 * if (isset(self::$styles[$styleName])) {   // $styleName is null
 * ```
 *
 * Nothing reachable from this library's API can avoid it, and left reported it
 * marks every document-writing test as deprecated, which buries anything real.
 * Rather than switch deprecation reporting off wholesale, exactly that message
 * from exactly that file is swallowed. Everything else, from this library or from
 * anywhere else, still reaches the test runner.
 *
 * It is installed for the duration of a test rather than around a single call,
 * because filling in a template writes a document for every block it inserts.
 */
final class Upstream
{
    private const NULL_OFFSET = 'Using null as an array offset';

    private const PHPWORD_STYLE = '/phpoffice/phpword/src/PhpWord/Style.php';

    private static bool $installed = false;

    /** @var (callable(int, string, string, int): bool)|null The handler this one displaces. */
    private static $previous = null;

    /**
     * Put the filter in place. Calling it twice is a no-op, so a test file can
     * install it and a helper can install it again without either noticing.
     */
    public static function install(): void
    {
        if (self::$installed) {
            return;
        }

        self::$installed = true;
        self::$previous = set_error_handler(
            static function (int $severity, string $message, string $file = '', int $line = 0): bool {
                if ($severity === E_DEPRECATED && self::isKnown($message, $file)) {
                    return true;
                }

                // A diagnostic the call site silenced with `@` stays silenced. The
                // library does that on clean-up calls that are expected to have
                // nothing to do, and the runner would otherwise report every one.
                if ((error_reporting() & $severity) === 0) {
                    return true;
                }

                // Hand everything else to the handler that was in place, which
                // during a test run is the runner's own. Returning false would
                // send it to PHP's default handler instead, and the runner would
                // never hear about it.
                $previous = self::$previous;

                return $previous === null
                    ? false
                    : (bool) $previous($severity, $message, $file, $line);
            }
        );
    }

    /**
     * Take the filter off again, leaving the previous handler in place at the
     * stack depth it had before.
     */
    public static function restore(): void
    {
        if (!self::$installed) {
            return;
        }

        self::$installed = false;
        self::$previous = null;
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
