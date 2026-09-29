<?php

declare(strict_types=1);

namespace MarkdownWord;

use RuntimeException;

/**
 * Thrown when a Word template cannot be loaded.
 */
final class TemplateNotFound extends RuntimeException
{
}
