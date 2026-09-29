<?php

declare(strict_types=1);

namespace MarkdownWord\Text;

use MarkdownWord\Render\LinkPayloadCollector;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Image;
use PhpOffice\PhpWord\Element\Link;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\PhpWord;

/**
 * Recovers the plain text of a rendered document.
 *
 * Useful for indexing, for accessibility fallbacks, and for asserting that a
 * conversion did not lose content.
 */
final class TextExtractor
{
    /** Separator inserted where a Word line break or paragraph boundary occurs. */
    public const LINE_BREAK = "\n";

    /**
     * @param list<array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}> $hyperlinks
     *        Payloads of links that are still represented by a placeholder in the
     *        element tree; their labels are substituted so the text is complete
     *        even before the document is written.
     */
    public static function fromPhpWord(
        PhpWord $phpWord,
        string $separator = self::LINE_BREAK,
        array $hyperlinks = [],
    ): string {
        $parts = [];
        foreach ($phpWord->getSections() as $section) {
            $parts[] = self::fromContainer($section, $separator, $hyperlinks);
        }

        return self::join($parts, $separator);
    }

    /**
     * @param list<array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}> $hyperlinks
     */
    public static function fromContainer(
        AbstractContainer $container,
        string $separator = self::LINE_BREAK,
        array $hyperlinks = [],
    ): string {
        $parts = [];

        foreach ($container->getElements() as $element) {
            $text = self::fromElement($element, $separator, $hyperlinks);

            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return self::join($parts, $separator);
    }

    /**
     * @param list<array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}> $hyperlinks
     */
    private static function fromElement(mixed $element, string $separator, array $hyperlinks = []): string
    {
        if ($element instanceof Text) {
            return LinkPayloadCollector::substitute($element->getText(), $hyperlinks);
        }

        if ($element instanceof Link) {
            return $element->getText();
        }

        if ($element instanceof TextBreak) {
            return self::LINE_BREAK;
        }

        if ($element instanceof Image) {
            return '';
        }

        if ($element instanceof ListItem) {
            return $element->getText();
        }

        if ($element instanceof TextRun) {
            return self::runs($element, $separator, $hyperlinks);
        }

        if ($element instanceof Table) {
            return self::table($element, $separator, $hyperlinks);
        }

        if ($element instanceof AbstractContainer) {
            return self::fromContainer($element, $separator, $hyperlinks);
        }

        return '';
    }

    /**
     * @param list<array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}> $hyperlinks
     */
    private static function runs(AbstractContainer $container, string $separator, array $hyperlinks = []): string
    {
        $parts = [];

        foreach ($container->getElements() as $child) {
            if ($child instanceof Text) {
                $parts[] = LinkPayloadCollector::substitute($child->getText(), $hyperlinks);
            } elseif ($child instanceof Link) {
                $parts[] = $child->getText();
            } elseif ($child instanceof Image) {
                $parts[] = $child->getName() ?? '';
            } elseif ($child instanceof TextBreak) {
                $parts[] = self::LINE_BREAK;
            } elseif ($child instanceof AbstractContainer) {
                $parts[] = self::fromElement($child, $separator, $hyperlinks);
            }
        }

        // Runs inside one paragraph are joined without a separator: a paragraph
        // is a single line in Word, so its runs form one line of text.
        return implode('', $parts);
    }

    /**
     * @param list<array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}> $hyperlinks
     */
    private static function table(Table $table, string $separator, array $hyperlinks = []): string
    {
        $rows = [];

        foreach ($table->getRows() as $row) {
            $cells = [];
            foreach ($row->getCells() as $cell) {
                $cells[] = self::fromContainer($cell, $separator, $hyperlinks);
            }
            $rows[] = implode("\t", $cells);
        }

        return implode(self::LINE_BREAK, $rows);
    }

    /**
     * @param  list<string>  $parts
     */
    private static function join(array $parts, string $separator): string
    {
        $text = implode($separator, $parts);

        // Only line breaks are stripped at the boundaries: leading spaces are
        // content in a code block and must survive.
        return trim($text, "\n\r");
    }
}
