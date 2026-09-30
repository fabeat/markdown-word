<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

/**
 * The dependency cannot write an element this library asked it to write.
 *
 * Not something a caller can cause or fix, and not a failure of the input: the
 * library rendered a document and handed the dependency something it has no
 * writer for. It is named so that a report of it is a report about the
 * dependency rather than about the document.
 */
final class UnsupportedElement extends Exception
{
}
