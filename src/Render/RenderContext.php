<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

/**
 * Immutable per-block rendering state.
 *
 * Block quoting and list nesting are the only two pieces of state that survive
 * across recursion levels, so they live here rather than being threaded through
 * a dozen method signatures.
 */
final class RenderContext
{
    public function __construct(
        public readonly int $quoteDepth = 0,
        public readonly int $listDepth = 0,
    ) {
    }

    public function inQuote(): bool
    {
        return $this->quoteDepth > 0;
    }

    public function enterQuote(): self
    {
        return new self($this->quoteDepth + 1, $this->listDepth);
    }

    public function enterList(int $depth): self
    {
        return new self($this->quoteDepth, $depth);
    }
}
