<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

/**
 * The input is not what the conversion needs.
 *
 * The common factor is that the caller can do something about it: pass a
 * different thing. A failure on this library's own side — no room on the disk,
 * say — is not this, and is not the caller's to fix.
 */
class InvalidInput extends Exception
{
}
