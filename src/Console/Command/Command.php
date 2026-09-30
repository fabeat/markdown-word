<?php

declare(strict_types=1);

namespace MarkdownWord\Console\Command;

use MarkdownWord\Console\Application;

/**
 * A command the {@see Application} can run.
 *
 * An interface so that the application can hold a list of commands without
 * knowing what any of them do, and so that the base class both directions share
 * and the help-text builder have one contract to read: the options a command
 * takes, and the exit code it answers with.
 *
 * The option list here is the one for readers, {@see self::spec()} the one for
 * the parser. They are two lists, and {@see self::spec()} says why.
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
     * What the help text is built from, written for someone reading it. The
     * parser is given {@see self::spec()} instead, and a test holds the two in
     * step, so the help cannot fall out of line with what is accepted.
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
