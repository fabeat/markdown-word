<?php

declare(strict_types=1);

namespace MarkdownWord\Parser;

use League\CommonMark\Node\Block\Document;

/**
 * Turns Markdown source into a `league/commonmark` document tree.
 *
 * Implement this to plug in a different Markdown dialect, or to pre-configure
 * the CommonMark environment (extensions, renderers, ...) in your own way.
 */
interface MarkdownParserInterface
{
    public function parse(string $markdown): Document;
}
