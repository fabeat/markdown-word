<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

/**
 * The library rendered a document and handed PHPWord an element it has no writer
 * for. Not the caller's to cause or fix, and not a failure of the input, which
 * is why it is not an {@see InvalidInput}: a report of it is a report about the
 * dependency rather than about the document.
 */
final class UnsupportedElement extends Exception
{
}
