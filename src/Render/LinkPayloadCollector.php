<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Extension\Strikethrough\Strikethrough;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use PhpOffice\PhpWord\Element\AbstractContainer;

/**
 * Handles links whose label contains emphasis, for example `[**Release** notes](url)`.
 *
 * Word models this as a `w:hyperlink` element wrapping several runs, but
 * PHPWord's `Link` element only accepts a single plain string. Rather than drop
 * either the link or the formatting, this class emits an opaque placeholder run
 * and records the real structure. {@see \MarkdownWord\Writer\HyperlinkPass} later
 * swaps the placeholder for a genuine `w:hyperlink` while it rewrites
 * `word/document.xml`.
 */
final class LinkPayloadCollector
{
    /**
     * @var list<array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}>
     */
    private array $payloads = [];

    private int $counter = 0;

    public function __construct(private readonly StyleResolver $styles)
    {
    }

    /**
     * The label collapses into a single placeholder run, so the container is
     * unchanged: no soft break inside a link can split the paragraph.
     */
    public function render(Link $node, AbstractContainer $target, InlineStyle $style): AbstractContainer
    {
        // The walk starts from the caller's style, which already carries the
        // link's url, its title and the font forced onto the surrounding block.
        // `InlineStyle` is immutable, so the siblings after this one are unaffected.
        $runs = $this->collectRuns($node->children(), $style);

        $placeholder = LinkPlaceholder::forIndex($this->counter++);
        $this->payloads[] = [
            'placeholder' => $placeholder,
            'url' => $style->url ?? $node->getUrl(),
            'title' => $style->linkTitle,
            'runs' => $runs,
        ];

        $target->addText($placeholder, null);

        return $target;
    }

    /**
     * The visible text of a collected link: its placeholder replaced by the label
     * it stands for.
     *
     * @param list<array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}> $payloads
     */
    public static function substitute(string $text, array $payloads): string
    {
        foreach ($payloads as $payload) {
            if (str_contains($text, $payload['placeholder'])) {
                $label = implode('', array_column($payload['runs'], 'text'));
                $text = str_replace($payload['placeholder'], $label, $text);

                break;
            }
        }

        return $text;
    }

    /**
     * @return list<array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}>
     */
    public function payloads(): array
    {
        return $this->payloads;
    }

    public function hasPayloads(): bool
    {
        return $this->payloads !== [];
    }

    /**
     * @param  iterable<Node>  $inlines
     * @return list<array{text: string, style: array<string, mixed>}>
     */
    private function collectRuns(iterable $inlines, InlineStyle $style): array
    {
        $runs = [];

        foreach ($inlines as $inline) {
            if ($inline instanceof Text) {
                $runs[] = ['text' => $inline->getLiteral(), 'style' => $this->fontArray($style)];
            } elseif ($inline instanceof Newline) {
                $runs[] = ['text' => ' ', 'style' => $this->fontArray($style)];
            } elseif ($inline instanceof Strong) {
                $runs = array_merge($runs, $this->collectRuns($inline->children(), $style->withBold()));
            } elseif ($inline instanceof Emphasis) {
                $runs = array_merge($runs, $this->collectRuns($inline->children(), $style->withItalic()));
            } elseif ($inline instanceof Strikethrough) {
                $runs = array_merge($runs, $this->collectRuns($inline->children(), $style->withStrikethrough()));
            } elseif ($inline instanceof Code) {
                $runs[] = ['text' => $inline->getLiteral(), 'style' => $this->fontArray($style->withCode())];
            } elseif ($inline->hasChildren()) {
                $runs = array_merge($runs, $this->collectRuns($inline->children(), $style));
            }
        }

        return $runs;
    }

    /**
     * @return array<string, mixed>
     */
    private function fontArray(InlineStyle $style): array
    {
        $font = $this->styles->fontFor($style);

        return is_array($font) ? $font : [];
    }
}
