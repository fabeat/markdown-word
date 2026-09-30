<?php

declare(strict_types=1);

namespace MarkdownWord;

/**
 * A conversion in one direction.
 *
 * The two directions are the same operation with the ends swapped, so one
 * interface describes both — worth nothing unless both are held to it. That is
 * why the constructor is not part of the contract: what an implementation takes
 * first is the thing to convert, and the configuration comes second, but the
 * second argument differs by direction ({@see Configuration} for
 * {@see MarkdownToWord}, {@see Reverse\Options} for {@see WordToMarkdown}) and no
 * signature covers both.
 *
 * ```php
 * new MarkdownToWord('notes.md', Configuration::create());
 * new WordToMarkdown($bytes, Options::fromArray([...]));
 * ```
 *
 * A string is a path to read when it names a file that exists and the content
 * itself otherwise — but only for Markdown. A Word document is a zip archive, so
 * {@see WordToMarkdown} insists on the `PK\x03\x04` header rather than taking an
 * unrecognised string for bytes: the rule {@see Input} applies, and the one the
 * command line works by.
 */
interface Converter
{
    /**
     * Convert whatever the converter was given.
     *
     * Written to `$target` when there is one, and returned either way, so the
     * same call serves a string and a file.
     *
     * @param string|null $target Where to write the result. Null returns it and
     *        writes nothing. A path of `-` is not special here; that is the
     *        command line's shorthand, and the caller decides what to do with a
     *        path.
     * @return string The converted document or Markdown.
     *
     * @throws Exception\NothingToConvert   when the converter was built without
     *        a source.
     * @throws Exception\UnreadableFile     when the source names a file that
     *        cannot be read.
     * @throws Exception\UnreadableDocument when the source is not a Word
     *        document that can be opened — only WordToMarkdown reads a source
     *        this way, but both re-open the archive they have just written.
     * @throws Exception\MalformedDocument  when a part of it is missing or does
     *        not parse.
     * @throws Exception\FileNotWritable    when there is a target and it cannot
     *        be written.
     */
    public function convert(?string $target = null): string;

    /**
     * Convert whatever the converter was given and write the result to a file.
     *
     * The counterpart of {@see self::convert()} for when the result is wanted on
     * disk and its value is not. Throws what {@see self::convert()} throws, and
     * never less.
     *
     * @param string $target Where to write the result.
     */
    public function save(string $target): void;
}
