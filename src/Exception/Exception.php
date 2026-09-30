<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

use RuntimeException;

/**
 * The base for every failure this library reports, so one `catch` covers all of
 * them without also catching unrelated failures from PHP itself and from PHPWord.
 *
 * It extends {@see RuntimeException} so that code written against the library
 * before these types existed keeps working.
 */
class Exception extends RuntimeException
{
}
