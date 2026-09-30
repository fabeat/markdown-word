<?php

declare(strict_types=1);

namespace MarkdownWord\Console\Command;

use MarkdownWord\Console\Application;

/**
 * A command the {@see Application} can run.
 *
 * {@see self::options()} is the help table, written for a reader;
 * {@see self::spec()} is what the parser is given. Two lists, held in step by a
 * test, because a help table reads nothing like a parser specification.
 */
interface Command
{
    /** @param list<string> $argv The arguments after the command name. */
    public function execute(array $argv): int;

    /**
     * The options this command accepts, as `[spelling, description]`.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function options(): array;

    /**
     * The long names the parser will accept for the options that take a value,
     * the on-or-off ones, the repeatable ones, and the short spellings.
     *
     * @return array{values: list<string>, flags: list<string>, repeated: list<string>, aliases: array<string, string>}
     */
    public static function spec(): array;
}
