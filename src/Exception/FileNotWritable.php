<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

/**
 * The input may have been perfectly good: a full disk, a directory that cannot
 * be made, a path the process cannot write to. All of it is this side rather
 * than the caller's mistake, which is why this is not an {@see InvalidInput}.
 */
final class FileNotWritable extends Exception
{
}
