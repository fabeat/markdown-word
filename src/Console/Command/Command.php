<?php

declare(strict_types=1);

namespace MarkdownWord\Console\Command;

use MarkdownWord\Console\Application;

/**
 * A command the {@see Application} can run.
 *
 * {@see self::options()} is the help table, written for a reader;
 * {@see self::spec()} is what the parser is given. They are held in step by a
 * test in both directions rather than derived from each other, because a help
 * table reads nothing like a parser specification.
 */
interface Command
{
    /**
     * @param list<string> $argv The arguments after the command name.
     */
    public function execute(array $argv): int;

    /**
     * The options this command accepts, as `[spelling, description]`.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function options(): array;

    /**
     * What the parser will accept: the long names of the options that take a
     * value, of the options that are on or off, of the options that may repeat,
     * and the short spellings.
     *
     * @return array{values: list<string>, flags: list<string>, repeated: list<string>, aliases: array<string, string>}
     */
    public static function spec(): array;
}
