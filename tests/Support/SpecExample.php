<?php

declare(strict_types=1);

namespace MarkdownWord\Tests\Support;

/**
 * A single specification example: Markdown in, expected HTML out.
 */
final class SpecExample
{
    public function __construct(
        public readonly int $number,
        public readonly string $section,
        public readonly string $markdown,
        public readonly string $html,
    ) {
    }

    public function label(): string
    {
        return sprintf('example %d (%s)', $this->number, $this->section);
    }
}
