<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

/**
 * The file is there and could not be read — permissions, most often. A different
 * thing from a file that is not a document ({@see UnreadableDocument}) or is not
 * there at all: only this one is likely to work on a second attempt.
 */
final class UnreadableFile extends InvalidInput
{
}
