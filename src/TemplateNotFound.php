<?php

declare(strict_types=1);

namespace MarkdownWord;

use MarkdownWord\Exception\Exception;

/**
 * Thrown when the Word document named as a template is not there.
 *
 * Left where it has always been, so that the name callers already import does
 * not move; it now sits under {@see Exception} with everything else the library
 * throws, which it already was a `RuntimeException` under.
 *
 * Everything else that can go wrong while a template is being rendered — a
 * staging file that cannot be created, a directory that cannot be made, a
 * document that cannot be written — is an
 * {@see \MarkdownWord\Exception\FileNotWritable} instead, which is what those
 * are.
 */
final class TemplateNotFound extends Exception
{
}
