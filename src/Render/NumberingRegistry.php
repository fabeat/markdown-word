<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Styles;
use PhpOffice\PhpWord\PhpWord;

/**
 * Creates and caches the Word numbering definitions used by Markdown lists.
 *
 * Word ties a list to a numbering definition, and a definition carries both the
 * bullet/decimal format and the starting value. A document containing `1.`,
 * `5.` and `a)` lists therefore needs three definitions, which is what this
 * registry hands out — while two consecutive `1.` lists share one.
 */
final class NumberingRegistry
{
    /**
     * CommonMark names the delimiter of an ordered list; Word wants the character
     * itself, since it is baked into the numbering text.
     */
    private const DELIMITERS = [
        'period' => '.',
        'paren' => ')',
    ];

    /**
     * @var array<string, string> Cache key => numbering style name.
     */
    private array $cache = [];

    /**
     * @var array<string, true> Base style names already written to the document.
     */
    private array $defined = [];

    private int $counter = 0;

    public function __construct(
        private readonly Configuration $config,
        private readonly PhpWord $phpWord,
    ) {
    }

    /**
     * @param bool        $ordered   Whether the list is ordered.
     * @param int|null    $start     The list's start value, if it is not 1.
     * @param string|null $delimiter `period` or `paren`, as CommonMark reports it.
     */
    public function styleFor(bool $ordered, ?int $start, ?string $delimiter): string
    {
        $start ??= 1;
        $delimiter = self::DELIMITERS[$delimiter ?? 'period'] ?? '.';

        $cacheKey = $ordered ? "o:$start:$delimiter" : "u:$delimiter";

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $base = (string) $this->config->getStyles()->get($ordered ? Styles::ORDERED_LIST : Styles::BULLET_LIST);
        $this->ensureDefined($base, $ordered);

        // The base style already encodes the common case, so only the variants
        // (a custom start value, a `)` delimiter) get their own definition.
        if ($this->isDefault($ordered, $start, $delimiter)) {
            return $this->cache[$cacheKey] = $base;
        }

        $name = $base . '-' . (++$this->counter);
        $this->define($name, $ordered, $start, $delimiter);

        return $this->cache[$cacheKey] = $name;
    }

    private function ensureDefined(string $name, bool $ordered): void
    {
        if (isset($this->defined[$name])) {
            return;
        }

        $this->define($name, $ordered, 1, '.');
        $this->defined[$name] = true;
    }

    private function isDefault(bool $ordered, int $start, string $delimiter): bool
    {
        if (!$ordered) {
            // Bullets are the only unordered form CommonMark defines.
            return true;
        }

        return $start === 1
            && $delimiter === '.'
            && $this->config->getOptions()->orderedListFormat === 'decimal';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function orderedLevels(int $start, string $delimiter): array
    {
        $options = $this->config->getOptions();
        $levels = [];

        for ($level = 0; $level < 9; $level++) {
            $levels[] = [
                'format' => $options->orderedListFormat,
                'text' => '%' . ($level + 1) . $delimiter,
                'left' => 720 * ($level + 1),
                'hanging' => 360,
                'tabPos' => 720 * ($level + 1),
                'start' => $level === 0 ? $start : 1,
                'suffix' => $options->orderedListSuffix ?? 'tab',
            ];
        }

        return $levels;
    }

    private function define(string $name, bool $ordered, int $start, string $delimiter): void
    {
        if ($ordered) {
            $this->phpWord->addNumberingStyle($name, [
                'type' => 'multilevel',
                'levels' => $this->orderedLevels($start, $delimiter),
            ]);

            return;
        }

        $this->phpWord->addNumberingStyle($name, [
            'type' => 'multilevel',
            'levels' => $this->bulletLevels(),
        ]);
    }

    /**
     * A bullet character for each nesting level.
     *
     * Every level has to be defined, not just the first: a nested list refers to
     * level 1 of the same numbering, and an undefined level makes Word fall back
     * to a different list — which is how a sub-list of bullets ends up numbered.
     */
    private const BULLETS = ["\u{2022}", 'o', "\u{25AA}"];

    /**
     * @return list<array<string, mixed>>
     */
    private function bulletLevels(): array
    {
        $levels = [];

        for ($level = 0; $level < 9; $level++) {
            $levels[] = [
                'format' => 'bullet',
                'text' => self::BULLETS[$level % count(self::BULLETS)],
                'left' => 720 * ($level + 1),
                'hanging' => 360,
                'tabPos' => 720 * ($level + 1),
                'suffix' => 'tab',
            ];
        }

        return $levels;
    }
}
