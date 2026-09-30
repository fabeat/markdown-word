<?php

declare(strict_types=1);

namespace MarkdownWord;

/**
 * A conversion in one direction. {@see MarkdownToWord} and {@see WordToMarkdown}
 * are the same operation with the ends swapped, so one interface describes both.
 *
 * The constructor is deliberately not in the contract: the second argument is a
 * {@see Configuration} one way and a {@see Reverse\Options} the other, and no
 * signature covers both. A string source is a path when it names a file and the
 * content otherwise ({@see Input}); {@see WordToMarkdown} also insists on the
 * `PK\x03\x04` of a zip archive, which is how the command line tells them apart.
 */
interface Converter
{
    /**
     * Written to `$target` when there is one and returned either way.
     *
     * @param string|null $target Null returns the result and writes nothing. A
     *        target of `-` means nothing here; that is the command line's
     *        shorthand, and what to do with a path is the caller's business.
     *
     * @throws Exception\NothingToConvert   when the converter was built without a source.
     * @throws Exception\UnreadableFile     when the source names a file that cannot be read.
     * @throws Exception\UnreadableDocument when the source is not a document that can be opened.
     * @throws Exception\MalformedDocument  when a part of one is missing or does not parse.
     * @throws Exception\FileNotWritable    when there is a target and it cannot be written.
     */
    public function convert(?string $target = null): string;

    /**
     * The counterpart of {@see self::convert()} for when the result is wanted on
     * disk and its value is not. Throws what {@see self::convert()} throws, and
     * never less.
     *
     * @param string $target Where to write the result.
     */
    public function save(string $target): void;
}
