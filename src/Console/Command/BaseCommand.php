<?php

declare(strict_types=1);

namespace MarkdownWord\Console\Command;

use MarkdownWord\Console\Application;
use MarkdownWord\Console\CommandLine;
use MarkdownWord\Console\ConsoleException;

/**
 * What the two directions have in common.
 *
 * Both are the same command with the ends swapped, and most of a run is the same
 * either way: take the options, deal with `--help`, check the argument says
 * nothing contradictory, work out where the result is going, and refuse to write
 * it over the file it came from. Only the conversion in the middle differs, so
 * that is all a subclass writes.
 */
abstract class BaseCommand implements Command
{
    public function __construct(protected readonly Application $application)
    {
    }

    /**
     * Parse the options, answer `--help`, and check nothing contradicts.
     *
     * Named for what it does rather than for the noun, because `options()` is
     * already the help table the interface asks for.
     *
     * @param list<string> $argv
     * @return CommandLine|null Null when the run was already answered, which is
     *         only ever the help.
     * @throws ConsoleException
     */
    final protected function parseOptions(array $argv, string $summary): ?CommandLine
    {
        $command = CommandLine::parse(
            $argv,
            values: static::spec()['values'],
            flags: static::spec()['flags'],
            repeated: static::spec()['repeated'],
            aliases: static::spec()['aliases'],
            // The options belonging to the other direction, so that reaching for
            // one by mistake names the command it does belong to.
            foreign: Application::specFor(static::other()),
        );

        if ($command->flag('help')) {
            $this->application->print(Application::commandHelp(static::class, $summary));

            return null;
        }

        static::assertDirection($command);

        return $command;
    }

    /**
     * Where the result should go, refusing to put it on top of its own input.
     *
     * Without the second half a run with no `-o` on a file whose extension is
     * already the one being written to would destroy that file, which is a
     * spectacular way to lose work.
     */
    final protected function outputPath(CommandLine $command, string $extension): string
    {
        $input = $command->input();
        $output = $this->application->outputPath($input, $extension, $command->value('output'));

        if ($input === null || $input === '-' || $output === '-') {
            return $output;
        }

        $from = realpath($input);
        $to = realpath(dirname($output) . '/' . basename($output));

        if ($from !== false && $to !== false && $from === $to) {
            throw new ConsoleException(
                sprintf('The result would overwrite the input file "%s".', $input),
                ['Pass --output to write it somewhere else.'],
            );
        }

        return $output;
    }

    /**
     * Say on standard error what a run converted and where it put it.
     */
    final protected function report(?string $input, string $output): void
    {
        $this->application->report($input, $output);
    }

    /**
     * A `--to` naming this direction is redundant; one naming the other is a
     * contradiction worth saying so about rather than ignoring.
     */
    final protected static function assertDirection(CommandLine $command): void
    {
        $asked = strtolower((string) $command->value('to'));

        if ($asked === '' || in_array($asked, static::formats(), true)) {
            return;
        }

        throw new ConsoleException(
            sprintf(
                '--to %s does not match what this reads; it takes %s.',
                $asked,
                implode(' or ', static::formats()),
            ),
            ['Omit it and let mdword work the direction out from the file.'],
        );
    }

    /**
     * The command in the other direction, for the options that are not this one's.
     *
     * @return class-string<Command>
     */
    abstract protected static function other(): string;

    /**
     * The formats `--to` may name for this direction.
     *
     * @return list<string>
     */
    abstract protected static function formats(): array;
}
