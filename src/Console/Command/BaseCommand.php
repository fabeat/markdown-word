<?php

declare(strict_types=1);

namespace MarkdownWord\Console\Command;

use MarkdownWord\Console\Application;
use MarkdownWord\Console\CommandLine;
use MarkdownWord\Console\ConsoleException;

/**
 * What the two directions have in common: the same command with the ends swapped,
 * so most of a run is the same either way. A subclass adds its own end of the
 * conversion and the options that configure it.
 */
abstract class BaseCommand implements Command
{
    public function __construct(protected readonly Application $application)
    {
    }

    /**
     * Parse the options, answer `--help`, and check nothing contradicts.
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
            // So that reaching for an option of the other direction by mistake
            // names the command it does belong to.
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
     * already the one being written to would destroy that file.
     */
    final protected function outputPath(CommandLine $command, string $extension): string
    {
        $input = $command->input();
        $output = $this->application->outputPath($input, $extension, $command->value('output'));

        $this->guardAgainstOverwrite($input, $output);

        return $output;
    }

    /**
     * Refuse a result that would land on the file it was made from.
     *
     * Called twice, and the second call is the point. The first happens before the
     * conversion, so that a run which is going to be refused stops before spending
     * the work. But a conversion is not instant, and anything with write access to
     * the output directory can put the input's own inode at the output path in
     * between — so each command looks again at the last moment.
     *
     * That narrows the window rather than closing it: what is left is the space
     * between the check and the write. Closing it needs the write to be conditional
     * on what it is about to replace, which `rename()` cannot promise and
     * `file_put_contents()` certainly does not.
     */
    final protected function guardAgainstOverwrite(?string $input, string $output): void
    {
        // Nothing to protect: a stream is not a file the run can land on.
        if ($input === null || $input === '-' || $output === '-') {
            return;
        }

        if (!self::namesTheInput($input, $output)) {
            return;
        }

        throw new ConsoleException(
            sprintf('The result would overwrite the input file "%s".', $input),
            ['Pass --output to write it somewhere else.'],
        );
    }

    /**
     * Whether the two paths are one file, whatever they happen to be called.
     *
     * By inode rather than by name: on a case-insensitive filesystem — APFS and
     * NTFS, which is what most macOS and Windows machines have — `Notes.md` and
     * `notes.md` are two spellings of one inode, and `realpath()` hands each back
     * in the case it was written in, so the two strings never match. A hard link
     * is two paths to one inode for the same reason, and a symlink to the input is
     * a third.
     *
     * A path that is not there cannot be the input, however it is spelled. The one
     * shape still worth catching is `sub/../notes.md`, and the detour in front of
     * it is exactly why the comparison cannot be left to `realpath()`, which
     * returns nothing at all for a path whose directory is missing. So the two are
     * compared as text, with the `.` and `..` segments taken out by hand.
     */
    private static function namesTheInput(string $input, string $output): bool
    {
        $from = realpath($input);
        $to = realpath($output);

        if ($from !== false && $to !== false) {
            return self::isTheSameFile($from, $to);
        }

        // The resolved path goes through the same normaliser too, which changes
        // nothing about it except the separators on Windows.
        return $from !== false && self::withoutDetours($output) === self::withoutDetours($from);
    }

    private static function isTheSameFile(string $left, string $right): bool
    {
        // Silenced because the path can be replaced by a directory between the
        // two `realpath()` calls above and this one, and refusing is still the
        // right answer when it has.
        $one = @stat($left);
        $other = @stat($right);

        return $one !== false
            && $other !== false
            && $one['dev'] === $other['dev']
            && $one['ino'] === $other['ino'];
    }

    /**
     * A path as it would be written out, with the `.` and `..` segments taken
     * out and a relative one anchored where it was written from.
     *
     * Textual, and so blind to one shape: where a `..` follows a symbolic link
     * the filesystem keeps the link's own directory and this throws the name
     * away. A refusal that was not necessary is the cheaper mistake to make in
     * a guard.
     */
    private static function withoutDetours(string $path): string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = str_replace('\\', '/', $path);
        }

        $prefix = preg_match('#^(?:[A-Za-z]:)?/#', $path, $matches) === 1 ? $matches[0] : '';
        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);

                continue;
            }

            $parts[] = $part;
        }

        $joined = implode('/', $parts);

        if ($prefix !== '') {
            return $prefix . $joined;
        }

        $working = getcwd();

        return $working === false ? $joined : $working . '/' . $joined;
    }

    /**
     * {@see Application::report()}, forwarded, so a command does not have to know
     * how the application says it.
     */
    final protected function report(?string $input, string $output): void
    {
        $this->application->report($input, $output);
    }

    /**
     * A `--to` naming this direction is redundant; one naming the other is a
     * contradiction worth reporting.
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
