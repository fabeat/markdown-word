<?php

declare(strict_types=1);

namespace MarkdownWord\Reverse;

/**
 * Resolves a paragraph's style name to the properties that style implies.
 *
 * A Word paragraph often says nothing about its indentation, because the
 * indentation lives in the style it references. Block quotes are the case that
 * matters: the outermost level inherits a half-inch indent from the style, so
 * without resolving it the nesting depth cannot be recovered at all.
 *
 * Properties are inherited through `w:basedOn`, and a paragraph's own direct
 * formatting wins over both, which is the same precedence Word applies.
 */
final class StyleTable
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * Style id => the `w:style` element defining it.
     *
     * @var array<string, \DOMElement>
     */
    private array $styles = [];

    /** @var array<string, array{indent: int, alignment: string}> */
    private array $resolved = [];

    public function __construct(?\DOMDocument $styles)
    {
        if ($styles === null) {
            return;
        }

        $xpath = new \DOMXPath($styles);
        $xpath->registerNamespace('w', self::W_NS);

        foreach ($xpath->query('//w:style') ?: [] as $style) {
            /** @var \DOMElement $style */
            $id = $style->getAttributeNS(self::W_NS, 'styleId');

            if ($id !== '') {
                $this->styles[$id] = $style;
            }
        }
    }

    public function has(string $id): bool
    {
        return isset($this->styles[$id]);
    }

    /**
     * The left indentation, in twips, a style contributes.
     */
    public function indentOf(string $id): int
    {
        return $this->resolve($id)['indent'];
    }

    /**
     * The paragraph alignment a style contributes, or an empty string.
     */
    public function alignmentOf(string $id): string
    {
        return $this->resolve($id)['alignment'];
    }

    /**
     * @return array{indent: int, alignment: string}
     */
    private function resolve(string $id, array $seen = []): array
    {
        if (isset($this->resolved[$id])) {
            return $this->resolved[$id];
        }

        // A style that refers to itself, directly or through a chain, would
        // otherwise recurse forever.
        if (isset($seen[$id]) || !isset($this->styles[$id])) {
            return ['indent' => 0, 'alignment' => ''];
        }

        $seen[$id] = true;
        $style = $this->styles[$id];
        $xpath = new \DOMXPath($style->ownerDocument ?? new \DOMDocument());
        $xpath->registerNamespace('w', self::W_NS);

        $parent = $style->getElementsByTagNameNS(self::W_NS, 'basedOn')->item(0);
        $inherited = $parent instanceof \DOMElement
            ? $this->resolve($parent->getAttributeNS(self::W_NS, 'val'), $seen)
            : ['indent' => 0, 'alignment' => ''];

        $own = $this->ownProperties($xpath, $style);

        return $this->resolved[$id] = [
            'indent' => $own['indent'] ?? $inherited['indent'],
            'alignment' => $own['alignment'] ?? $inherited['alignment'],
        ];
    }

    /**
     * @return array{indent?: int, alignment?: string}
     */
    private function ownProperties(\DOMXPath $xpath, \DOMElement $style): array
    {
        $properties = [];

        $indentation = $xpath->query('./w:pPr/w:ind', $style)?->item(0);
        if ($indentation instanceof \DOMElement) {
            $left = $indentation->getAttributeNS(self::W_NS, 'left');
            $properties['indent'] = $left === '' ? 0 : (int) $left;
        }

        $alignment = $xpath->query('./w:pPr/w:jc', $style)?->item(0);
        if ($alignment instanceof \DOMElement) {
            $properties['alignment'] = $alignment->getAttributeNS(self::W_NS, 'val');
        }

        return $properties;
    }
}
