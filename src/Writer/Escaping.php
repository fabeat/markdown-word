<?php

declare(strict_types=1);

namespace MarkdownWord\Writer;

/**
 * The former name of {@see OutputEscaping}, which reads as the Markdown text
 * escaping of {@see \MarkdownWord\Reverse\Escaping} and is nothing to do with
 * it.
 *
 * Kept only so that `MarkdownWord\Template\MarkdownTemplate` keeps working
 * while it is updated to the new name. Delete this file with that change.
 *
 * @deprecated Use {@see OutputEscaping} instead.
 */
final class Escaping
{
    /**
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     *
     * @see OutputEscaping::enabled()
     */
    public static function enabled(callable $write): mixed
    {
        return OutputEscaping::enabled($write);
    }
}
