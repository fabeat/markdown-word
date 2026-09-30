<?php

declare(strict_types=1);

namespace MarkdownWord;

/**
 * A conversion in one direction. {@see MarkdownToWord} and {@see WordToMarkdown}
 * are the same operation with the ends swapped, so one interface describes both.
 *
 * The constructor is deliberately not in the contract: the second argument is a
 * {@see Configuration} one way and a {@see Reverse\Options} the other, and no
 * signature covers both. {@see Input} says how a string is read, and the one
 * asymmetry is that {@see WordToMarkdown} also insists on `PK\x03\x04` — the
 * first bytes of a zip archive, and how the command line tells the two apart.
 */
interface Converter
{
    /**
     * Written to `$target` when there is one and returned either way.
     *
     * @param string|null $target Null writes nothing. A target of `-` is a file
     *        called `-` here: the command line resolves it to standard output
     *        before it gets this far.
     *
     * @throws Exception\NothingToConvert   when the converter was built without a source.
     * @throws Exception\UnreadableFile     when the source names a file that cannot be read.
     * @throws Exception\UnreadableDocument when the source is not a document that can be opened.
     * @throws Exception\MalformedDocument  when a part of one is missing or does not parse.
     * @throws Exception\FileNotWritable    when there is a target and it cannot be written.
     */
    public function convert(?string $target = null): string;

    /**
     * Throws what {@see self::convert()} throws, and never less.
     */
    public function save(string $target): void;
}
