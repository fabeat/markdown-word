<?php

declare(strict_types=1);

namespace MarkdownWord\Console;

use function array_key_exists;
use function array_shift;
use function in_array;
use function ltrim;
use function str_starts_with;
use function strpos;
use function substr;

/**
 * The command line as the user typed it, split into options and operands.
 *
 * Parsed against a declared set of options, so an unknown or malformed one is
 * reported in a single place, in a message that names it. A value may be written
 * `--output report.docx`, `--output=report.docx` or `-o report.docx`, which is
 * what a person at a terminal expects; a flag takes two of them, there being
 * nothing for a third spelling to carry. Everything after a bare `--` is an
 * operand, so a file whose name begins with a dash can still be converted.
 */
final class CommandLine
{
    /**
     * @param array<string, string>        $values   Options that take a value.
     * @param array<string, true>          $flags    Options that are simply on or off.
     * @param list<string>                 $repeated Options that may be given more than once.
     * @param list<string>                 $operands Everything that was not an option.
     */
    public function __construct(
        private readonly array $values = [],
        private readonly array $flags = [],
        private readonly array $repeated = [],
        private readonly array $operands = [],
    ) {
    }

    /**
     * Split raw arguments against a specification of what is allowed.
     *
     * @param list<string>         $argv    The arguments, without the program name.
     * @param array<string, string> $foreign Long names belonging to a *different*
     *        command, mapped to that command's name, so a slip can be reported as one.
     *
     * @throws ConsoleException on an unknown option, a missing value, or a value
     *         given to an option that takes none.
     */
    public static function parse(
        array $argv,
        array $values = [],
        array $flags = [],
        array $repeated = [],
        array $aliases = [],
        array $foreign = [],
    ): self {
        // Dashes come off first, so the alias table is keyed on the bare name and
        // `-o`, `--o` and `-o=x` all mean the same thing.
        $canonical = static function (string $name) use ($aliases): string {
            $bare = ltrim($name, '-');

            return $aliases[$bare] ?? $bare;
        };

        $taken = ['values' => [], 'flags' => [], 'repeated' => []];
        $operands = [];

        $literal = false;

        while ($argv !== []) {
            $argument = array_shift($argv);

            if ($literal) {
                $operands[] = $argument;

                continue;
            }

            if ($argument === '--') {
                $literal = true;

                continue;
            }

            if (!self::looksLikeOption($argument)) {
                $operands[] = $argument;

                continue;
            }

            $inline = null;
            $name = $argument;

            if (($equals = strpos($argument, '=')) !== false) {
                $name = substr($argument, 0, $equals);
                $inline = substr($argument, $equals + 1);
            }

            $name = $canonical($name);

            if (in_array($name, $flags, true)) {
                if ($inline !== null) {
                    throw new ConsoleException(sprintf('The option "%s" does not take a value.', $name));
                }

                $taken['flags'][$name] = true;

                continue;
            }

            if (in_array($name, $repeated, true)) {
                $taken['repeated'][$name][] = $inline ?? self::take($name, $argv);

                continue;
            }

            if (!in_array($name, $values, true)) {
                throw self::unknown($argument, $name, $foreign);
            }

            $taken['values'][$name] = $inline ?? self::take($name, $argv);
        }

        return new self($taken['values'], $taken['flags'], $taken['repeated'], $operands);
    }

    /**
     * The error for an option no command will take, saying where it does belong
     * when that is known.
     *
     * @param array<string, string> $foreign
     */
    private static function unknown(string $argument, string $name, array $foreign): ConsoleException
    {
        if (isset($foreign[$name])) {
            return new ConsoleException(
                sprintf('The option "%s" does not apply here.', $argument),
                [sprintf('It belongs to `%s`.', $foreign[$name])],
            );
        }

        return new ConsoleException(
            sprintf('Unknown option "%s".', $argument),
            ['Run `mdword help` to see what each command accepts.'],
        );
    }

    /**
     * @param list<string> $argv
     */
    private static function take(string $name, array &$argv): string
    {
        if ($argv === []) {
            throw new ConsoleException(sprintf('The option "%s" needs a value.', $name));
        }

        return array_shift($argv);
    }

    /**
     * A token naming an option rather than an operand. A bare `-` is the
     * conventional "standard input", so it is an operand.
     */
    public static function looksLikeOption(string $token): bool
    {
        return $token !== '' && $token !== '-' && str_starts_with($token, '-');
    }

    /**
     * The first operand — the input file — or null when none was given, which
     * means standard input.
     */
    public function input(): ?string
    {
        return $this->operands[0] ?? null;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    public function value(string $name, ?string $default = null): ?string
    {
        return $this->values[$name] ?? $default;
    }

    public function flag(string $name): bool
    {
        return isset($this->flags[$name]);
    }

    /**
     * @return list<string>
     */
    public function repeated(string $name): array
    {
        return $this->repeated[$name] ?? [];
    }

    /**
     * A `name=value` pair, as `--define customer=Northwind` gives.
     *
     * @return array{0: string, 1: string}
     * @throws ConsoleException when there is no `=` or the name is empty.
     */
    public static function pair(string $argument, string $option): array
    {
        $equals = strpos($argument, '=');

        if ($equals === false || $equals === 0) {
            throw new ConsoleException(
                sprintf('The option "%s" needs a name=value pair, but got "%s".', $option, $argument),
            );
        }

        return [substr($argument, 0, $equals), substr($argument, $equals + 1)];
    }
}
