<?php

declare(strict_types=1);

namespace MarkdownWord\Console\Command;

use MarkdownWord\Console\Application;

/**
 * A command the {@see Application} can run.
 *
 * Kept as an interface so the dispatcher holds a list of them, and so the option
 * list that builds the help text is the same one the parser is given.
 */
interface Command
{
    /**
     * @param list<string> $argv The arguments after the command name.
     * @return int The exit code.
     */
    public function execute(array $argv): int;

    /**
     * The options this command accepts, as `[spelling, description]`.
     *
     * Used to build the help text, so it cannot fall out of step with what the
     * parser actually allows.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function options(): array;

    /**
     * What the parser will accept: the long names of the options that take a
     * value, of the options that are on or off, of the options that may repeat,
     * and the short spellings.
     *
     * The same names as {@see self::options()}, without the descriptions — one
     * for the parser and one for the reader. They are kept in step by a test
     * rather than derived from each other, because a help table reads nothing
     * like a parser specification.
     *
     * @return array{values: list<string>, flags: list<string>, repeated: list<string>, aliases: array<string, string>}
     */
    public static function spec(): array;
}
