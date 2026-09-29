<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\Table\Table as MarkdownTable;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Extension\Table\TableRow;
use League\CommonMark\Extension\Table\TableSection;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Options;
use MarkdownWord\Configuration\Styles;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Style\Paragraph as WordParagraph;

/**
 * Walks the CommonMark document tree and emits the equivalent Word elements.
 *
 * The traversal is explicit rather than a visitor registry: the block structure
 * of a Word document is flat, and naming every node type that is handled makes
 * it obvious when something would be silently dropped.
 */
final class DocumentRenderer
{
    /**
     * The indentation of one level of block quote, in twips (a twentieth of a
     * point), which is half an inch.
     */
    private const QUOTE_INDENT = 720;

    public function __construct(
        private readonly Configuration $config,
        private readonly StyleResolver $styles,
        private readonly InlineRenderer $inlines,
        private readonly NumberingRegistry $numbering,
        private readonly HtmlFragmentRenderer $html,
    ) {
    }

    public function render(Document $document, AbstractContainer $target): void
    {
        $this->renderChildren($document->children(), $target, new RenderContext());
    }

    /**
     * @param iterable<Node> $nodes
     */
    private function renderChildren(iterable $nodes, AbstractContainer $target, RenderContext $context): void
    {
        foreach ($nodes as $node) {
            $this->renderNode($node, $target, $context);
        }
    }

    private function renderNode(Node $node, AbstractContainer $target, RenderContext $context): void
    {
        if ($node instanceof Heading) {
            $this->renderHeading($node, $target, $context);

            return;
        }

        if ($node instanceof Paragraph) {
            $this->renderParagraph($node, $target, $context);

            return;
        }

        if ($node instanceof ThematicBreak) {
            $this->renderThematicBreak($target, $context);

            return;
        }

        if ($node instanceof FencedCode || $node instanceof IndentedCode) {
            $this->renderCodeBlock($node, $target, $context);

            return;
        }

        if ($node instanceof BlockQuote) {
            $this->renderChildren($node->children(), $target, $context->enterQuote());

            return;
        }

        if ($node instanceof ListBlock) {
            $this->renderList($node, $target, $context);

            return;
        }

        if ($node instanceof MarkdownTable) {
            $this->renderTable($node, $target, $context);

            return;
        }

        if ($node instanceof TableSection) {
            $this->renderChildren($node->children(), $target, $context);

            return;
        }

        // Only block-level HTML comes through here. Inline HTML is part of a
        // paragraph and is handled by the inline renderer, which has to splice it
        // into the run rather than start a new one.
        if ($node instanceof HtmlBlock) {
            $this->renderHtml($node, $target, $context);

            return;
        }

        if ($node instanceof Document) {
            $this->renderChildren($node->children(), $target, $context);

            return;
        }

        if ($node->hasChildren()) {
            $this->renderChildren($node->children(), $target, $context);
        }
    }

    // ------------------------------------------------------------------ blocks

    private function renderHeading(Heading $node, AbstractContainer $target, RenderContext $context): void
    {
        $maxLevel = $this->config->getOptions()->maxHeadingLevel;
        $level = $node->getLevel();

        // Inside a block quote a heading would break out of the quote visually,
        // so it is drawn as an emphasised paragraph instead.
        $slot = $level <= $maxLevel && !$context->inQuote()
            ? 'heading.' . $level
            : Styles::PARAGRAPH;

        $this->emitParagraph($node->children(), $target, $context, $slot);
    }

    private function renderParagraph(Paragraph $node, AbstractContainer $target, RenderContext $context): void
    {
        $this->emitParagraph($node->children(), $target, $context, Styles::PARAGRAPH);
    }

    /**
     * Render the inline children of a block as one or more paragraphs.
     *
     * The style slot is resolved here so that its character half can be applied
     * to the runs. A Word paragraph carries no character formatting of its own,
     * so `heading.1 => ['size' => 20, 'bold' => true]` only works if the size and
     * the weight reach the runs inside the paragraph.
     *
     * @param iterable<Node> $inlines
     */
    private function emitParagraph(
        iterable $inlines,
        AbstractContainer $target,
        RenderContext $context,
        string $slot,
    ): void {
        $style = $this->blockStyle($slot, $context);
        $forcedFont = $this->slotFont($slot, $context);

        $run = $target->addTextRun($style);

        $this->inlines->render(
            $inlines,
            $run,
            new InlineStyle(forcedFont: $forcedFont),
            function () use ($target, $style, $forcedFont): AbstractContainer {
                // `softBreak => 'paragraph'`: the inline renderer asks for a new
                // container whenever a soft break should become a real paragraph.
                $next = $target->addTextRun($style);
                $this->inlines->render([], $next, new InlineStyle(forcedFont: $forcedFont));

                return $next;
            },
        );
    }

    /**
     * The character formatting a style slot asks for, taking the surrounding
     * block quote into account.
     *
     * @return array<string, mixed>
     */
    private function slotFont(string $slot, RenderContext $context): array
    {
        if ($context->inQuote() && in_array($slot, [Styles::PARAGRAPH, Styles::LIST_PARAGRAPH], true)) {
            $slot = Styles::BLOCK_QUOTE;
        }

        return ParagraphStyle::fontPart($this->styles->slot($slot));
    }

    private function renderThematicBreak(AbstractContainer $target, RenderContext $context): void
    {
        $style = $this->styles->paragraphStyleFor(Styles::THEMATIC_BREAK);

        if ($this->config->getOptions()->thematicBreak !== 'text' && $style === null) {
            // Word has no horizontal rule element, so a rule is a paragraph with
            // a bottom border.
            $style = [
                'borderBottomStyle' => 'single',
                'borderBottomSize' => 6,
                'borderBottomColor' => 'auto',
                'space' => ['before' => 120, 'after' => 120],
            ];
        }

        $run = $target->addTextRun($this->applyContext($style, $context));
        $run->addText($this->config->getOptions()->thematicBreak === 'text' ? str_repeat('-', 40) : '');
    }

    private function renderCodeBlock(FencedCode|IndentedCode $node, AbstractContainer $target, RenderContext $context): void
    {
        $literal = rtrim($node->getLiteral(), "\n");
        if ($literal === '') {
            return;
        }

        $paragraphStyle = $this->blockStyle(Styles::CODE_BLOCK, $context) ?? $this->codeBlockStyle();

        // A code block's own font slot wins over the code span font, so a
        // configured `codeBlock` style can change the typeface of the block.
        $font = array_merge(
            (array) $this->styles->fontFor(new InlineStyle(code: true)),
            ParagraphStyle::fontPart($this->styles->slot(Styles::CODE_BLOCK)),
        );

        foreach (explode("\n", $literal) as $line) {
            $run = $target->addTextRun($paragraphStyle);
            $run->addText($line, $font);
        }
    }

    /**
     * @return array<string, mixed>|string|null
     */
    private function codeBlockStyle(): array|string|null
    {
        $style = ParagraphStyle::paragraphPart($this->styles->slot(Styles::CODE_BLOCK));

        if ($this->config->getOptions()->codeBlockShading) {
            $style = ParagraphStyle::merge($style, [
                'shading' => ['fill' => 'F2F2F2'],
                'space' => ['before' => 0, 'after' => 0],
            ]);
        }

        return $style;
    }

    private function renderList(ListBlock $node, AbstractContainer $target, RenderContext $context): void
    {
        $data = $node->getListData();
        $ordered = $data->type === ListBlock::TYPE_ORDERED;
        $styleName = $this->numbering->styleFor($ordered, $data->start, $data->delimiter);

        $this->renderListChildren($node, $target, $context, $styleName, $node->isTight());
    }

    /**
     * @param ListBlock $list
     */
    private function renderListChildren(
        ListBlock $list,
        AbstractContainer $target,
        RenderContext $context,
        string $styleName,
        bool $tight,
    ): void {
        foreach ($list->children() as $item) {
            if (!$item instanceof ListItem) {
                continue;
            }
            $this->renderListItem($item, $target, $context, $styleName, $tight);
        }
    }

    private function renderListItem(
        ListItem $item,
        AbstractContainer $target,
        RenderContext $context,
        string $styleName,
        bool $tight,
    ): void {
        $depth = $context->listDepth;
        $paragraphStyle = $this->listParagraphStyle($tight, $context);
        $itemFont = $this->slotFont(Styles::LIST_PARAGRAPH, $context);
        $rendered = 0;

        foreach ($item->children() as $child) {
            if ($child instanceof ListBlock) {
                $nestedData = $child->getListData();
                $nestedStyle = $this->numbering->styleFor(
                    $nestedData->type === ListBlock::TYPE_ORDERED,
                    $nestedData->start,
                    $nestedData->delimiter,
                );
                $this->renderListChildren(
                    $child,
                    $target,
                    $context->enterList($depth + 1),
                    $nestedStyle,
                    $child->isTight(),
                );
                continue;
            }

            if ($child instanceof Paragraph) {
                $itemRun = $target->addListItemRun($depth, $styleName, $paragraphStyle);
                $this->inlines->render($child->children(), $itemRun, new InlineStyle(forcedFont: $itemFont));
                $rendered++;
                continue;
            }

            // A code block, quote or table inside a list item cannot be a Word
            // list item, so it is emitted as a normal block right after it.
            if ($child instanceof AbstractBlock) {
                $this->renderNode($child, $target, $context);
                $rendered++;
            }
        }

        // `- ` with no content still has to produce a visible bullet.
        if ($rendered === 0) {
            $target->addListItemRun($depth, $styleName, $paragraphStyle);
        }
    }

    /**
     * The paragraph style for a list item.
     *
     * A list inside a block quote belongs to the quote, so it is indented by the
     * quote's depth. The quote's own style cannot simply be reused: Word resolves
     * a named style wholesale, while a list item needs an indentation of its own
     * for the list level, so the offset is applied as an inline style instead.
     *
     * @return string|array|WordParagraph|null
     */
    private function listParagraphStyle(bool $tight, RenderContext $context): string|array|WordParagraph|null
    {
        $style = ParagraphStyle::paragraphPart($this->styles->slot(Styles::LIST_PARAGRAPH));

        if ($tight) {
            $style = ParagraphStyle::merge($style, ['space' => ['before' => 0, 'after' => 0]]);
        }

        if (!$context->inQuote()) {
            return $style;
        }

        $offset = ['indentation' => ['left' => self::QUOTE_INDENT * $context->quoteDepth]];

        return is_string($style) ? $offset : ParagraphStyle::merge($style, $offset);
    }

    // ------------------------------------------------------------------ tables

    private function renderTable(MarkdownTable $node, AbstractContainer $target, RenderContext $context): void
    {
        $options = $this->config->getOptions();
        $tableStyle = $this->styles->slot(Styles::TABLE);

        $table = match (true) {
            $tableStyle === null => $target->addTable($this->defaultTableStyle($options)),
            // A named table style comes from the target document — typically a
            // Word built-in grid — and is used exactly as the template defines it.
            is_string($tableStyle) => $target->addTable($tableStyle),
            // A custom definition still gets the configured width, so that
            // styling a table does not quietly make it narrow again.
            default => $target->addTable(ParagraphStyle::table(
                ParagraphStyle::merge($this->widthStyle($options), $tableStyle),
            )),
        };

        $alignments = $this->columnAlignments($node);
        $headerRowStyle = $this->styles->slot(Styles::TABLE_HEADER_ROW);
        $cellStyle = $this->styles->slot(Styles::TABLE_CELL);

        $isHeader = true;

        foreach ($this->tableRows($node) as $row) {
            $table->addRow(null, $isHeader ? $this->rowStyle($headerRowStyle) : null);

            $column = 0;
            foreach ($this->rowCells($row) as $cell) {
                $align = $alignments[$column] ?? null;

                $tableCell = $table->addCell(null, $this->cellStyle($cellStyle));
                $this->renderCellContent(
                    $cell,
                    $tableCell,
                    $context,
                    $this->cellFont($cellStyle, $isHeader, $options),
                    $this->cellAlignment($align, $cellStyle),
                );
                $column++;
            }

            $isHeader = false;
        }
    }

    /**
     * @param  array<string, mixed>  $forcedFont
     */
    private function renderCellContent(
        TableCell $cell,
        AbstractContainer $tableCell,
        RenderContext $context,
        array $forcedFont = [],
        ?string $alignment = null,
    ): void {
        $inlineStyle = new InlineStyle(forcedFont: $forcedFont);
        $paragraphStyle = $alignment === null ? null : ['alignment' => $alignment];
        $blocks = 0;

        foreach ($cell->children() as $child) {
            if ($child instanceof Paragraph) {
                $run = $tableCell->addTextRun($paragraphStyle);
                $this->inlines->render($child->children(), $run, $inlineStyle);
            } elseif ($child instanceof AbstractBlock) {
                $this->renderNode($child, $tableCell, $context);
            } else {
                $run = $tableCell->addTextRun($paragraphStyle);
                $this->inlines->render([$child], $run, $inlineStyle);
            }
            $blocks++;
        }

        if ($blocks === 0) {
            $tableCell->addTextRun($paragraphStyle);
        }
    }

    /**
     * @return list<TableRow>
     */
    private function tableRows(MarkdownTable $table): array
    {
        $rows = [];

        foreach ($table->children() as $section) {
            if ($section instanceof TableSection) {
                foreach ($section->children() as $row) {
                    if ($row instanceof TableRow) {
                        $rows[] = $row;
                    }
                }
            } elseif ($section instanceof TableRow) {
                $rows[] = $section;
            }
        }

        return $rows;
    }

    /**
     * @return list<TableCell>
     */
    private function rowCells(TableRow $row): array
    {
        $cells = [];
        foreach ($row->children() as $cell) {
            if ($cell instanceof TableCell) {
                $cells[] = $cell;
            }
        }

        return $cells;
    }

    /**
     * GFM stores the column alignment in the delimiter row of the header.
     *
     * @return list<string|null>
     */
    private function columnAlignments(MarkdownTable $table): array
    {
        $alignments = [];

        foreach ($table->children() as $section) {
            if (!$section instanceof TableSection || $section->getType() !== TableSection::TYPE_HEAD) {
                continue;
            }

            foreach ($section->children() as $row) {
                if (!$row instanceof TableRow) {
                    continue;
                }
                foreach ($this->rowCells($row) as $index => $cell) {
                    $alignments[$index] = $cell->getAlign();
                }
            }
        }

        return $alignments;
    }

    /**
     * PHPWord's cell style has no font or alignment properties, so those parts of
     * the configured cell style are extracted and applied to the runs and
     * paragraphs inside the cell instead. Only the genuinely cell-level
     * properties (shading, margins, borders) are passed to the cell itself.
     *
     * @return array<string, mixed>
     */
    private function cellStyle(mixed $configured): array
    {
        if (!is_array($configured)) {
            return [];
        }

        $cellOnly = $configured;
        unset($cellOnly['bold'], $cellOnly['italic'], $cellOnly['alignment']);

        return $cellOnly;
    }

    /**
     * The font forced onto every run of a cell.
     *
     * @return array<string, mixed>
     */
    private function cellFont(mixed $configured, bool $isHeader, Options $options): array
    {
        $font = is_array($configured) ? $configured : [];
        unset($font['alignment']);

        if ($isHeader && $options->tableHeaderBold) {
            $font['bold'] = true;
        }

        return $font;
    }

    /**
     * The horizontal alignment of a column: the one written in the delimiter row,
     * unless the configured cell style overrides it.
     */
    private function cellAlignment(?string $markdownAlign, mixed $configured): ?string
    {
        $configuredAlign = is_array($configured) ? ($configured['alignment'] ?? null) : null;

        if (is_array($configuredAlign)) {
            return $configuredAlign['horizontal'] ?? null;
        }

        if (is_string($configuredAlign)) {
            return $configuredAlign;
        }

        return match ($markdownAlign) {
            'right' => 'right',
            'center' => 'center',
            'left' => 'left',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rowStyle(mixed $configured): ?array
    {
        return is_array($configured) ? $configured : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultTableStyle(Options $options): array
    {
        $style = $this->widthStyle($options) + [
            'alignment' => 'left',
            'layout' => 'autofit',
        ];

        if ($options->tableBorders) {
            $style['borderColor'] = '000000';
            $style['borderSize'] = 6;
            $style['cellMargin'] = 80;
        }

        return $style;
    }

    /**
     * The table's width, expressed the way OOXML wants it.
     *
     * PHPWord's default is `w:tblW w:w="0" w:type="auto"`, and a zero width makes
     * every viewer shrink the table to its shortest content rather than filling
     * the text column. A percentage of the column is the stable way to ask for
     * full width, because it follows the page size and the margins.
     *
     * @return array<string, mixed>
     */
    private function widthStyle(Options $options): array
    {
        if ($options->tableWidth <= 0) {
            return ['width' => 0, 'unit' => 'auto'];
        }

        return ['width' => $options->tableWidth, 'unit' => 'pct'];
    }

    // -------------------------------------------------------------------- html

    private function renderHtml(Node $node, AbstractContainer $target, RenderContext $context): void
    {
        $run = $target->addTextRun($this->blockStyle(Styles::HTML_FALLBACK, $context));

        $this->html->render($this->literalOf($node), $run);
    }

    private function literalOf(Node $node): string
    {
        if ($node instanceof Text || $node instanceof HtmlBlock || $node instanceof HtmlInline) {
            return $node->getLiteral();
        }

        $text = '';
        foreach ($node->children() as $child) {
            $text .= $this->literalOf($child);
        }

        return $text;
    }

    // ----------------------------------------------------------------- helpers

    /**
     * The style for a block, taking the surrounding context into account.
     *
     * Inside a block quote an ordinary paragraph is drawn with the quote style
     * instead, which is what makes quoted text look quoted without the Markdown
     * author having to say so.
     *
     * @return string|array|WordParagraph|null
     */
    private function blockStyle(string $slot, RenderContext $context): string|array|WordParagraph|null
    {
        // Any level of block quote is drawn with the quote style; only the
        // indentation changes with depth.
        $style = $slot === Styles::PARAGRAPH && $context->inQuote()
            ? $this->styles->paragraphStyleFor(Styles::BLOCK_QUOTE)
            : $this->styles->slot($slot);

        // The character half is applied to the runs instead, because PHPWord
        // would discard it here.
        $style = ParagraphStyle::paragraphPart($style);

        return $this->applyContext($style, $context);
    }

    /**
     * Fold block-quote indentation into a paragraph style.
     *
     * A quoted paragraph is indented by {@see self::QUOTE_INDENT} for each level
     * of nesting, so a quote inside a quote visibly steps in. The outermost level
     * uses a configured named style verbatim, because that style already carries
     * its own indentation.
     *
     * @param  string|array|WordParagraph|null  $style
     * @return string|array|WordParagraph|null
     */
    private function applyContext(string|array|WordParagraph|null $style, RenderContext $context): string|array|WordParagraph|null
    {
        if (!$context->inQuote()) {
            return $style;
        }

        $indent = ['indentation' => ['left' => self::QUOTE_INDENT * $context->quoteDepth]];

        if (is_string($style)) {
            return $context->quoteDepth === 1
                ? $style
                : ParagraphStyle::namedWith($style, $indent);
        }

        return ParagraphStyle::merge($style, $indent);
    }
}
