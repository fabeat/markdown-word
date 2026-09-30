<?php

declare(strict_types=1);

namespace MarkdownWord\Tests\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;

/**
 * Renders Markdown to the text a browser would show, and normalises text for
 * comparison.
 *
 * `league/commonmark` is a fully conforming implementation, so its output is by
 * definition the text the specification asks for — which is what would make
 * {@see self::of()} a fair yardstick for a Word document. Only
 * {@see self::normalise()} is reached today; nothing calls `of()` or `html()`.
 */
final class MarkdownText
{
    public static function of(string $markdown, bool $gfm = true): string
    {
        return HtmlText::extract(self::html($markdown, $gfm));
    }

    public static function html(string $markdown, bool $gfm = true): string
    {
        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());

        if ($gfm) {
            $environment->addExtension(new GithubFlavoredMarkdownExtension());
        }

        return (string) (new HtmlRenderer($environment))->renderDocument(
            (new MarkdownParser($environment))->parse($markdown),
        );
    }

    /**
     * The visible text with the layout differences flattened out: runs of spaces
     * and tabs become one space and blank lines are dropped, because where a line
     * wraps and whether a list is loose are layout questions. Line breaks are
     * kept, since that is where structure such as list items and table rows shows
     * up.
     */
    public static function normalise(string $text): string
    {
        $lines = array_map(
            static fn (string $line): string => rtrim(trim((string) preg_replace('/[ \t]+/', ' ', $line)), " \t"),
            explode("\n", $text),
        );

        $lines = array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));

        return implode("\n", $lines);
    }
}
