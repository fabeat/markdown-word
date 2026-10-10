<?php

declare(strict_types=1);

namespace MarkdownWord;

use League\CommonMark\Node\Block\Document;
use MarkdownWord\Document\ConfigurationMerger;
use MarkdownWord\Document\Frontmatter;
use MarkdownWord\Exception\NothingToConvert;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Parser\MarkdownParserInterface;
use MarkdownWord\Render\DocumentRenderer;
use MarkdownWord\Render\HtmlFragmentRenderer;
use MarkdownWord\Render\ImageConversionCollector;
use MarkdownWord\Render\SvgAttachmentCollector;
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
 * Converts Markdown into a Word document, read by default with
 * {@see CommonMarkParser} — pass another one to change the dialect. See
 * {@see Converter} for what this and {@see WordToMarkdown} share.
 */
final class MarkdownToWord implements Converter
{
    private ?LinkPayloadCollector $links = null;

    private ?ImageDescriptionCollector $images = null;

    private ?ImageConversionCollector $conversions = null;

    private ?SvgAttachmentCollector $vectors = null;

    /**
     * The Markdown of the document last rendered, kept for one reason: a wrong key
     * in a `---` block has to be reported with a line number, and the block is only
     * text in the source. The parse tree keeps positions for what is left after the
     * block, so it cannot answer for the block itself.
     */
    private string $lastMarkdown = '';

    /**
     * @param string|null $source A path, or the Markdown itself; {@see Input}
     *        says which a string is. Null leaves the subject to
     *        {@see self::toDocx()}.
     * @param Configuration|array|null $overrides The layer above the frontmatter. The
     *        array form is the one to reach for: it is sparse, so a key nobody
     *        mentioned leaves the document's own setting standing, where a
     *        `Configuration` names every setting there is and would put the defaults
     *        above the frontmatter along with everything else.
     */
    public function __construct(
        private readonly ?string $source = null,
        private readonly Configuration $config = new Configuration(),
        private readonly ?MarkdownParserInterface $parser = null,
        private readonly Configuration|array|null $overrides = null,
    ) {
    }

    /**
     * The configuration passed in, without the frontmatter of whichever document was
     * last rendered.
     *
     * That is what the constructor holds and not what was used, because the frontmatter
     * belongs to a document rather than to the converter: a converter handed two
     * documents with different frontmatter renders each with its own.
     */
    public function getConfiguration(): Configuration
    {
        return $this->config;
    }

    /**
     * The configuration a given document is rendered with, frontmatter included.
     *
     * Public because the template path asks before rendering anything into it, and
     * because "which configuration would this document use" is otherwise only
     * answerable by rendering it and looking.
     *
     * @param string|null $markdown The Markdown the document was parsed from, when the
     *        caller still has it. Only the `---` block's line numbers come from it, and
     *        {@see self::toPhpWord()} supplies it on every path that renders.
     *
     * @throws Exception\InvalidConfiguration when the block names nothing, or gives a
     *         value that is not what it is used as.
     */
    public function configurationFor(Document $document, ?string $markdown = null): Configuration
    {
        $frontmatter = Frontmatter::fromDocument($document, $markdown ?? $this->lastMarkdown);

        // Checked before it is merged, so a key that names nothing is reported against
        // the file the reader wrote rather than swallowed by the merge — and so the
        // whole set of them arrives at once instead of one per run.
        $frontmatter->assertValid();

        return ConfigurationMerger::resolve(
            commandLine: is_array($this->overrides) ? $this->overrides : $this->overrides?->toArray(),
            frontmatter: $frontmatter,
            // `toArray()` is lossless, so applying it over the defaults rebuilds the
            // constructor's configuration exactly rather than half of it.
            configFile: $this->config->toArray(),
        );
    }

    public function parse(string $markdown): Document
    {
        return ($this->parser ?? new CommonMarkParser())->parse($markdown);
    }

    /**
     * The document is made self-contained: the style definitions the renderer
     * references are written into it, so it looks the same everywhere.
     */
    public function toPhpWord(string $markdown, ?PhpWord $phpWord = null): PhpWord
    {
        $phpWord ??= new PhpWord();
        $this->lastMarkdown = $markdown;
        $document = $this->parse($markdown);

        $section = $phpWord->getSections() === []
            ? $phpWord->addSection()
            : $phpWord->getSections()[count($phpWord->getSections()) - 1];

        $this->renderInto($document, $section, $phpWord, defineStyles: true);

        return $phpWord;
    }

    /**
     * Render Markdown into an existing container — a section, a table cell, a
     * header or a footer. No style definitions are written: the destination
     * document, a template most likely, is the authority on what its styles look
     * like, and defining them here would override the author's design.
     */
    public function renderIntoContainer(
        string $markdown,
        AbstractContainer $container,
        ?PhpWord $phpWord = null,
    ): AbstractContainer {
        $phpWord ??= new PhpWord();
        $this->lastMarkdown = $markdown;
        $document = $this->parse($markdown);

        $this->renderInto($document, $container, $phpWord, defineStyles: false);

        return $container;
    }

    /**
     * @throws NothingToConvert             when the converter was built without a source.
     * @throws Exception\UnreadableFile     when the source names a file that cannot be read.
     * @throws Exception\UnsupportedImageFormat when a document names an image that is
     *        on disk and in a format neither Word nor the local GD build can take.
     * @throws Exception\UnreadableDocument when the finished archive cannot be reopened.
     * @throws Exception\MalformedDocument  when a part of it is not XML.
     * @throws Exception\FileNotWritable    when the document cannot be written.
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
            return DocxWriter::toString($phpWord, $this->links, $this->images, $this->vectors);
        }

        // Through the writer rather than `file_put_contents`: it stages the archive
        // in the temp directory and moves it into place, so a half-written document
        // is never left where someone will open it, and a directory that is not
        // there yet is made rather than warned about.
        return DocxWriter::write($phpWord, $target, $this->links, $this->images, $this->vectors);
    }

    public function save(string $target): void
    {
        $this->convert($target);
    }

    /**
     * Markdown as the raw bytes of a `.docx`, the counterpart of
     * {@see \MarkdownWord\WordToMarkdown::toMarkdown()}.
     */
    public function toDocx(string $markdown, ?PhpWord $phpWord = null): string
    {
        $phpWord = $this->toPhpWord($markdown, $phpWord);

        return DocxWriter::toString($phpWord, $this->links, $this->images, $this->vectors);
    }

    /**
     * Every hyperlink payload collected so far — they accumulate across renders —
     * keyed by the placeholder standing in for each in the element tree.
     *
     * @return list<array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}>
     */
    public function pendingHyperlinks(): array
    {
        return $this->links?->payloads() ?? [];
    }

    /**
     * The images that were decoded into PNG because Word cannot embed them as they
     * were, accumulated across renders.
     *
     * A `.webp` is the case there is: it is what a browser has been asked for for
     * years and PHPWord has never supported it. The picture is embedded rather than
     * replaced by its alt text, but it is re-encoded on the way in and a PNG of a
     * photograph is several times the size of the WebP it came from, so a caller
     * should be able to see that it happened. The command line prints one line per
     * entry.
     *
     * @return list<array{source: string, format: string, embeddedAs: string}>
     */
    public function pendingImageConversions(): array
    {
        return $this->conversions?->all() ?? [];
    }

    private function renderInto(
        Document $document,
        AbstractContainer $container,
        PhpWord $phpWord,
        bool $defineStyles,
    ): void {
        $config = $this->configurationFor($document);
        $styles = new StyleResolver($config);

        if ($defineStyles) {
            (new StyleRegistrar($config->getStyles()))->register($phpWord);
        }

        // Kept across renders so the placeholder indices stay unique, which matters
        // when several documents go through one converter, as the template does.
        $this->links ??= new LinkPayloadCollector($styles);

        $html = new HtmlFragmentRenderer($config->getOptions(), $styles);
        $this->images ??= new ImageDescriptionCollector();
        $this->conversions ??= new ImageConversionCollector();
        $this->vectors ??= new SvgAttachmentCollector();

        $inlines = new InlineRenderer(
            $styles,
            new ImageResolver($config->getOptions(), $this->images, $this->conversions, $this->vectors),
            $this->links,
            $html,
            $config->getOptions()->deferredHyperlinks,
        );

        $renderer = new DocumentRenderer(
            $config,
            $styles,
            $inlines,
            new NumberingRegistry($this->config, $phpWord),
            $html,
        );

        $renderer->render($document, $container);
    }
}
