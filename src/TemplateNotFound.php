<?php

declare(strict_types=1);

namespace MarkdownWord;

use MarkdownWord\Exception\Exception;

/**
 * Thrown when a Word template cannot be loaded.
 *
 * Left where it has always been, so that the name callers already import does
 * not move; it now sits under {@see Exception} with everything else the library
 * throws, which it already was a `RuntimeException` under.
 */
final class TemplateNotFound extends Exception
{
}
