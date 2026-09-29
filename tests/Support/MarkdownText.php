<?php

declare(strict_types=1);

namespace MarkdownWord\Tests\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;

/**
 * Renders Markdown to the text a browser would show, which is the oracle the
 * conformance suite and the round-trip suite both compare against.
 *
 * `league/commonmark` is a fully conforming implementation, so the visible text
 * of its output is by definition the text the specification asks for. That makes
 * it a fair yardstick for what a Word document should contain — and, because the
 * comparison is on rendered text rather than on markup, a fair yardstick for the
 * round trip too, where the second conversion's source is the Markdown the first
 * conversion produced.
 */
final class MarkdownText
{
    /**
     * The visible text of a Markdown document.
     */
    public static function of(string $markdown, bool $gfm = true): string
    {
        return HtmlText::extract(self::html($markdown, $gfm));
    }

    /**
     * The HTML a browser would render for a Markdown document.
     */
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
     * The visible text, with the layout differences that are not content
     * differences flattened out.
     *
     * Runs of spaces and tabs collapse to one space and blank lines are dropped,
     * because where a line wraps and whether a list is loose are layout
     * questions. Line breaks are kept, since that is where structure such as
     * list items and table rows shows up.
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
