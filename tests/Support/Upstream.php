<?php

declare(strict_types=1);

namespace MarkdownWord\Tests\Support;

use MarkdownWord\Console\UpstreamDeprecations;

/**
 * Hides one known defect in a dependency, from a test run.
 *
 * PHPWord 1.4.0 reads its style registry with a null array offset while writing
 * `word/styles.xml` — once per paragraph style it emits, whatever the document
 * holds:
 *
 * ```php
 * if (isset(self::$styles[$styleName])) {   // $styleName is null
 * ```
 *
 * `Style\Paragraph::writeNumbering()` makes that call one line before it tests
 * `$numStyle !== null`, so no document this library writes can avoid it. Left
 * reported it marks every document-writing test as deprecated, which buries
 * anything real; exactly that message from exactly that file is swallowed rather
 * than deprecation reporting being switched off.
 *
 * Which diagnostic is the known one is decided by {@see UpstreamDeprecations},
 * the same filter the command line installs. A second copy of the rule is a
 * second thing to keep right, and the two have drifted once.
 *
 * `install()` runs for the duration of a test because a write need not go
 * through a wrapping helper, and the writer raises the diagnostic either way.
 */
final class Upstream
{
    /**
     * Put the filter in place. A second call is a no-op, so a test file can
     * install it and a helper can install it again without either noticing.
     */
    public static function install(): void
    {
        UpstreamDeprecations::install();
    }

    /**
     * Take the filter off again, leaving the handler it replaced where it was.
     */
    public static function restore(): void
    {
        UpstreamDeprecations::restore();
    }

    public static function quietly(callable $work): mixed
    {
        return UpstreamDeprecations::quietly($work);
    }
}
