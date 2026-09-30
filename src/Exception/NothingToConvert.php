<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

/**
 * A converter takes its subject in its constructor, so reaching this means it
 * was built without one and {@see \MarkdownWord\Converter::convert()} was called
 * rather than a method that takes its own argument.
 */
final class NothingToConvert extends InvalidInput
{
}
