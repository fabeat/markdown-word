<?php

declare(strict_types=1);

namespace MarkdownWord\Tests\Support;

use MarkdownWord\Console\UpstreamDeprecations;

/**
 * Hides one known defect in a dependency, from a test run.
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
 * from exactly that file is swallowed.
 *
 * It is installed for the duration of a test rather than around a single call,
 * because filling in a template writes a document for every block it inserts.
 *
 * Which diagnostic is the known one is not decided here: it is
 * {@see UpstreamDeprecations}, the same filter the command line installs, and
 * this class is that one under the name the suite already calls. A second copy of
 * the rule is a second thing to keep right, and the two had already drifted: the
 * copy also swallowed everything the runner was not reporting — which, with the
 * runner's own error_reporting in force, is nearly everything — so the two
 * filters had quietly come to disagree about what "one known defect" meant.
 */
final class Upstream
{
    /**
     * Put the filter in place. Calling it twice is a no-op, so a test file can
     * install it and a helper can install it again without either noticing.
     */
    public static function install(): void
    {
        UpstreamDeprecations::install();
    }

    /**
     * Take the filter off again, leaving the previous handler in place at the
     * stack depth it had before.
     */
    public static function restore(): void
    {
        UpstreamDeprecations::restore();
    }

    /**
     * Run a closure with the filter in place.
     */
    public static function quietly(callable $work): mixed
    {
        return UpstreamDeprecations::quietly($work);
    }
}
