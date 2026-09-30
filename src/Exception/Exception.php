<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

use RuntimeException;

/**
 * The base for everything this library throws.
 *
 * Catching this catches every failure from the library and nothing else, which
 * is what a caller embedding it wants; catching {@see RuntimeException} also
 * catches unrelated failures from PHP itself and from PHPWord.
 *
 * It extends {@see RuntimeException} so that code written against the library
 * before these existed keeps working.
 */
class Exception extends RuntimeException
{
}
