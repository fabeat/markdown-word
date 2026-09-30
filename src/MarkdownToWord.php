<?php

declare(strict_types=1);

namespace MarkdownWord;

use League\CommonMark\Node\Block\Document;
use MarkdownWord\Exception\NothingToConvert;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Parser\MarkdownParserInterface;
use MarkdownWord\Render\DocumentRenderer;
use MarkdownWord\Render\HtmlFragmentRenderer;
use MarkdownWord\Render\ImageDescriptionCollector;
use MarkdownWord\Render\ImageResolver;
use MarkdownWord\Render\InlineRenderer;
use MarkdownWord\Render\LinkPayloadCollector;
use MarkdownWord\Render\NumberingRegistry;
use MarkdownWord\Render\StyleRegistrar;
use MarkdownWord\Render\StyleResolver;
use MarkdownWord\Writer\DocxWriter;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\PhpWord;

/**
 * Converts Markdown into a Word document.
 *
 * ```php
 * // Markdown in a string, a document out.
 * $converter = new MarkdownToWord();
 * $phpWord = $converter->toPhpWord('# Hello *World*');
 *
 * // A file in, a file out.
 * (new MarkdownToWord('notes.md'))->save('notes.docx');
 *
 * // A file in, bytes out.
 * $bytes = (new MarkdownToWord('notes.md'))->convert();
 * ```
 *
 * The parser is `league/commonmark`, so CommonMark and GitHub-Flavored Markdown
 * are both fully supported — the renderer walks the syntax tree directly instead
 * of going through HTML, which is what keeps the output faithful.
 *
 * The other direction is {@see WordToMarkdown}, and the two implement the same
 * {@see Converter} interface so that either can stand in for the other.
 */
final class MarkdownToWord implements Converter
{
    private ?LinkPayloadCollector $links = null;

    private ?ImageDescriptionCollector $images = null;

    /**
     * @param string|null $source The Markdown to convert: a path, or the text
     *        itself. Null leaves the choice to {@see self::toDocx()} and friends.
     */
    public function __construct(
        private readonly ?string $source = null,
        private readonly Configuration $config = new Configuration(),
        private readonly ?MarkdownParserInterface $parser = null,
    ) {
    }

    public function getConfiguration(): Configuration
    {
        return $this->config;
    }

    /**
     * Parse Markdown into a CommonMark document tree.
     */
    public function parse(string $markdown): Document
    {
        return ($this->parser ?? new CommonMarkParser())->parse($markdown);
    }

    /**
     * Render Markdown into a new `PhpWord` document.
     *
     * The document is made self-contained: the style definitions the renderer
     * references are written into it, so it looks the same everywhere.
     */
    public function toPhpWord(string $markdown, ?PhpWord $phpWord = null): PhpWord
    {
        $phpWord ??= new PhpWord();
        $document = $this->parse($markdown);

        $section = $phpWord->getSections() === []
            ? $phpWord->addSection()
            : $phpWord->getSections()[count($phpWord->getSections()) - 1];

        $this->renderInto($document, $section, $phpWord, defineStyles: true);

        return $phpWord;
    }

    /**
     * Render Markdown into an existing container — a section, a table cell, a
     * header or a footer.
     *
     * No style definitions are written: the destination document — a template,
     * most likely — is the authority on what its styles look like, and defining
     * them here would override the author's design.
     */
    public function renderIntoContainer(
        string $markdown,
        AbstractContainer $container,
        ?PhpWord $phpWord = null,
    ): AbstractContainer {
        $phpWord ??= new PhpWord();
        $document = $this->parse($markdown);

        $this->renderInto($document, $container, $phpWord, defineStyles: false);

        return $container;
    }

    /**
     * Convert the source given to the constructor.
     *
     * Written to `$target` when there is one, and returned either way, so the
     * same call serves a string and a file.
     *
     * @throws RuntimeException when no source was given.
     */
    public function convert(?string $target = null): string
    {
        if ($this->source === null) {
            throw new NothingToConvert(
                'There is no Markdown to convert. Give some to the constructor, '
                . 'or to ' . self::class . '::toDocx().',
            );
        }

        $markdown = Input::markdown($this->source);
        $phpWord = $this->toPhpWord($markdown);

        if ($target === null) {
            return DocxWriter::toString($phpWord, $this->links, $this->images);
        }

        // Through the writer rather than `file_put_contents`, because the writer
        // stages the archive in the temp directory and moves it into place: a
        // half-written document cannot be left where someone will open it, and a
        // directory that does not exist yet is made rather than written to and
        // warned about.
        return DocxWriter::write($phpWord, $target, $this->links, $this->images);
    }

    /**
     * Convert the source and write the document to a file.
     */
    public function save(string $target): void
    {
        $this->convert($target);
    }

    /**
     * Write Markdown to a `.docx` file and return its raw bytes, the counterpart
     * of {@see \MarkdownWord\WordToMarkdown::toMarkdown()}.
     */
    public function toDocx(string $markdown, ?PhpWord $phpWord = null): string
    {
        $phpWord = $this->toPhpWord($markdown, $phpWord);

        return DocxWriter::toString($phpWord, $this->links, $this->images);
    }

    /**
     * The hyperlink payloads collected during the last render, keyed by the
     * placeholder that stands in for them in the element tree.
     *
     * @return list<array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}>
     */
    public function pendingHyperlinks(): array
    {
        return $this->links?->payloads() ?? [];
    }

    private function renderInto(
        Document $document,
        AbstractContainer $container,
        PhpWord $phpWord,
        bool $defineStyles,
    ): void {
        $styles = new StyleResolver($this->config);

        if ($defineStyles) {
            (new StyleRegistrar($this->config->getStyles()))->register($phpWord);
        }

        // The collector is kept across renders so its placeholder indices stay
        // unique, which matters when several documents are rendered through the
        // same converter, as the template renderer does.
        $this->links ??= new LinkPayloadCollector($styles);

        $html = new HtmlFragmentRenderer($this->config->getOptions(), $styles);
        $this->images ??= new ImageDescriptionCollector();

        $inlines = new InlineRenderer(
            $styles,
            new ImageResolver($this->config->getOptions(), $this->images),
            $this->links,
            $html,
            $this->config->getOptions()->deferredHyperlinks,
        );

        $renderer = new DocumentRenderer(
            $this->config,
            $styles,
            $inlines,
            new NumberingRegistry($this->config, $phpWord),
            $html,
        );

        $renderer->render($document, $container);
    }
}
