<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

/**
 * The converter was given nothing to convert.
 *
 * A converter takes its subject in its constructor, so reaching this means it
 * was built without one and the string-returning method that takes its own
 * argument was not the one that was called.
 */
final class NothingToConvert extends InvalidInput
{
}
