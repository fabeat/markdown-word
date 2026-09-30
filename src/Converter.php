<?php

declare(strict_types=1);

namespace MarkdownWord;

/**
 * A conversion in one direction.
 *
 * The two directions are the same operation with the ends swapped, so they are
 * described by one interface. Before this they shared a shape by agreement,
 * which is worth nothing: a method could be renamed on one side and every caller
 * using the other would be the only thing to notice.
 *
 * ```php
 * function convert(Converter $converter, string $target): void
 * {
 *     $converter->save($target);   // Markdown in, or a document in — either works
 * }
 * ```
 *
 * ## What the subject is
 *
 * An implementation is given the thing it converts in its constructor, as the
 * first argument: a path, or the content itself. A string naming a file that
 * exists is read from it, and anything else is taken as the content — the rule
 * {@see Input} applies, and the same one the command line works by.
 *
 * The second argument is what configures the conversion, and it differs by
 * direction: {@see MarkdownToWord} takes a {@see Configuration}, and
 * {@see WordToMarkdown} takes a {@see Reverse\Options}. That is why the
 * constructor is not part of this contract: there is no signature both could
 * honour. Pass `null` to skip it.
 *
 * ```php
 * new MarkdownToWord('notes.md');                          // the defaults
 * new MarkdownToWord('notes.md', Configuration::create()); // configured
 * new WordToMarkdown('report.docx');
 * new WordToMarkdown($bytes, Options::fromArray([...]));
 * ```
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
     * @throws \RuntimeException when the converter was given nothing to convert.
     */
    public function convert(?string $target = null): string;

    /**
     * Convert whatever the converter was given and write the result to a file.
     *
     * The counterpart of {@see self::convert()} for when the result is wanted on
     * disk and its value is not.
     *
     * @param string $target Where to write the result.
     */
    public function save(string $target): void;
}
