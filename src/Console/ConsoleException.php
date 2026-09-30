<?php

declare(strict_types=1);

namespace MarkdownWord\Console;

use RuntimeException;

/**
 * An error the person at the terminal can do something about.
 *
 * Thrown for a bad command, a missing file, a nonsensical option — everything
 * where the right response is a sentence rather than a stack trace. The
 * application catches it, prints the sentence and stops. Anything else is a
 * defect in this library and is left to surface as one.
 */
final class ConsoleException extends RuntimeException
{
    /**
     * @param list<string> $hints Lines printed after the message, each one
     *        suggesting what to try instead.
     */
    public function __construct(string $message, private readonly array $hints = [])
    {
        parent::__construct($message);
    }

    /**
     * @return list<string>
     */
    public function hints(): array
    {
        return $this->hints;
    }
}
