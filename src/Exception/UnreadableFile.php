<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

/**
 * A file that exists but could not be read.
 *
 * Permissions, most often. It is a different thing from a file that is not a
 * document, and from one that is not there at all, because only this one is
 * likely to work on a second attempt.
 */
final class UnreadableFile extends InvalidInput
{
}
