<?php

declare(strict_types=1);

namespace MarkdownWord\Reverse;

/**
 * Resolves a `w:numId` to the shape of the list it produces.
 *
 * A Word list is a reference to a numbering definition rather than a marker in
 * the text, so `1.` is not stored anywhere in the document. Recovering it takes
 * three hops: the paragraph names a `w:numId` and a level, the `w:num` points at
 * an abstract definition, and the level inside that carries the format, the
 * marker text and the start value.
 */
final class NumberingTable
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * Num id => level => the list shape at that level.
     *
     * @var array<int, array<int, array{format: string, text: string, start: int}>>
     */
    private array $levels = [];

    public function __construct(?\DOMDocument $numbering)
    {
        if ($numbering === null) {
            return;
        }

        $xpath = new \DOMXPath($numbering);
        $xpath->registerNamespace('w', self::W_NS);

        // The abstract definitions first, keyed by their own id.
        $abstract = [];
        foreach ($xpath->query('//w:abstractNum') ?: [] as $definition) {
            /** @var \DOMElement $definition */
            $abstract[$definition->getAttributeNS(self::W_NS, 'abstractNumId')] = $definition;
        }

        // Then each concrete `w:num`, which is a thin pointer to one of them.
        foreach ($xpath->query('//w:num') ?: [] as $num) {
            /** @var \DOMElement $num */
            $id = (int) $num->getAttributeNS(self::W_NS, 'numId');

            $target = $xpath->query('./w:abstractNumId', $num)?->item(0);
            if (!$target instanceof \DOMElement) {
                continue;
            }

            $definition = $abstract[$target->getAttributeNS(self::W_NS, 'val')] ?? null;
            if ($definition === null) {
                continue;
            }

            $this->levels[$id] = $this->readLevels($xpath, $definition);
        }
    }

    public function knows(int $numId): bool
    {
        return isset($this->levels[$numId]);
    }

    /**
     * @return array{format: string, text: string, start: int}|null
     */
    public function level(int $numId, int $level): ?array
    {
        return $this->levels[$numId][$level] ?? null;
    }

    /**
     * The format of the outermost level, which decides the marker style of the
     * whole list.
     *
     * A definition that declares no levels has no outermost one, which is not the
     * same as declaring them out of order: the sort below tells the two apart,
     * and it has nothing to say about an empty list.
     *
     * @return array{format: string, text: string, start: int}|null
     */
    public function root(int $numId): ?array
    {
        $levels = $this->levels[$numId] ?? [];

        if ($levels === []) {
            return null;
        }

        ksort($levels);

        return $levels[array_key_first($levels)];
    }

    /**
     * @return array<int, array{format: string, text: string, start: int}>
     */
    private function readLevels(\DOMXPath $xpath, \DOMElement $definition): array
    {
        $levels = [];

        foreach ($xpath->query('./w:lvl', $definition) ?: [] as $level) {
            /** @var \DOMElement $level */
            $index = (int) $level->getAttributeNS(self::W_NS, 'ilvl');

            $levels[$index] = [
                'format' => $this->childValue($xpath, $level, 'numFmt') ?? 'decimal',
                'text' => $this->childValue($xpath, $level, 'lvlText') ?? '',
                'start' => (int) ($this->childValue($xpath, $level, 'start') ?? '1'),
            ];
        }

        return $levels;
    }

    private function childValue(\DOMXPath $xpath, \DOMElement $parent, string $name): ?string
    {
        $node = $xpath->query('./w:' . $name, $parent)?->item(0);

        return $node instanceof \DOMElement
            ? $node->getAttributeNS(self::W_NS, 'val')
            : null;
    }
}
