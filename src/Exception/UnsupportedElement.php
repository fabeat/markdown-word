<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

/**
 * PHPWord had no Word 2007 writer for an element the library rendered. Not the
 * caller's to cause or fix, and not a failure of the input, which is why it is
 * not an {@see InvalidInput}: a report of it is a report about the dependency.
 */
final class UnsupportedElement extends Exception
{
}
