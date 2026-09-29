<?php

declare(strict_types=1);

namespace MarkdownWord;

use League\CommonMark\Node\Block\Document;
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
 * $converter = new MarkdownToWord();
 * $phpWord = $converter->toPhpWord('# Hello *World*');
 * $converter->save('# Hello', 'hello.docx');
 * ```
 *
 * The parser is `league/commonmark`, so CommonMark and GitHub-Flavored Markdown
 * are both fully supported — the renderer walks the syntax tree directly instead
 * of going through HTML, which is what keeps the output faithful.
 */
final class MarkdownToWord
{
    private ?LinkPayloadCollector $links = null;

    private ?ImageDescriptionCollector $images = null;

    public function __construct(
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
     * Write Markdown straight to a `.docx` file.
     */
    public function save(string $markdown, string $path, ?PhpWord $phpWord = null): void
    {
        $phpWord = $this->toPhpWord($markdown, $phpWord);

        DocxWriter::write($phpWord, $path, $this->links, $this->images);
    }

    /**
     * Write Markdown to a `.docx` file and return its raw bytes.
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
