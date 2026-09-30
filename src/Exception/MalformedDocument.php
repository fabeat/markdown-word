<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

/**
 * The archive opened and something inside it is not: a part every document is
 * expected to have is missing, or the XML in one does not parse. Distinct from a
 * file that is not a document at all, so a caller can tell the two apart.
 */
final class MalformedDocument extends UnreadableDocument
{
}
