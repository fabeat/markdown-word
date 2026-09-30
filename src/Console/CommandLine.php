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
 * Parsed against a declared set of options rather than left to the application
 * to interpret raw strings, so an unknown or malformed option is reported in one
 * place, in a message that names the option.
 *
 * A value may be written three ways, which is what a person at a terminal
 * expects, while a flag takes two of them — it is on or off, so there is
 * nothing for a third spelling to carry:
 *
 * ```text
 * --output report.docx
 * --output=report.docx
 * -o report.docx
 * ```
 *
 * Everything after a bare `--` is an operand, so a file whose name begins with a
 * dash can still be converted.
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
     * @param list<string> $argv The arguments, without the program name.
     * @param list<string> $values    Long names of the options that take a value.
     * @param list<string> $flags     Long names of the options that are on or off.
     * @param list<string> $repeated  Long names of the options that may repeat.
     * @param array<string, string> $aliases Short spellings mapped to long names.
     * @param array<string, string> $foreign Long names that belong to a *different*
     *        command, mapped to the name that command is called by. Naming one of
     *        these is nearly always a slip rather than a typo, so it is worth
     *        saying which command it does belong to.
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
        // The dashes come off first, so that the alias table is keyed on the bare
        // name and `-o`, `--o` and `-o=x` all mean the same thing.
        $canonical = static function (string $name) use ($aliases): string {
            $bare = ltrim($name, '-');

            return $aliases[$bare] ?? $bare;
        };

        $taken = ['values' => [], 'flags' => [], 'repeated' => []];
        $operands = [];

        // Everything after a bare `--` is an operand, which is the conventional
        // escape hatch for a file whose name starts with a dash.
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

            // `--output=report.docx` carries the value on the same token, so the
            // split happens once, here, rather than at every use.
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
     * Whether a token names an option rather than an operand. A bare `-` is the
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
