<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

/**
 * A file or directory could not be created, opened or written.
 *
 * The input may have been perfectly good: a full disk, a directory that does
 * not exist and cannot be made, or a path the process has no permission to
 * write to. All of it is this side rather than the caller's mistake, which is
 * why this is not an {@see InvalidInput}.
 */
final class FileNotWritable extends Exception
{
}
