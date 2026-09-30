<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

use RuntimeException;

/**
 * The base for every failure this library reports, so one `catch` covers all of
 * them without also catching PHPWord's. It extends {@see RuntimeException} for
 * the code written before these types existed.
 */
class Exception extends RuntimeException
{
}
