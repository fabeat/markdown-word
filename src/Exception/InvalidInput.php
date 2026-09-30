<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

/**
 * Something the caller can fix by passing something else. A failure on this
 * library's own side — no room on the disk, say — is not this, and is not the
 * caller's to fix: {@see FileNotWritable} and {@see UnsupportedElement} are.
 */
class InvalidInput extends Exception
{
}
