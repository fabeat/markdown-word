<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Styles;
use PhpOffice\PhpWord\PhpWord;

/**
 * Creates and caches the Word numbering definitions used by Markdown lists.
 *
 * A Word list is tied to a numbering definition, and a definition carries both
 * the format and the starting value, so `1.`, `5.` and `1)` in one document need
 * three of them where two `1.` lists share one.
 */
final class NumberingRegistry
{
    /**
     * CommonMark names an ordered list's delimiter; Word wants the character
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
     * `$delimiter` is CommonMark's `period` or `paren`, as the parser reports it;
     * null is read as `period`.
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

        // The base style covers the default list; a custom start, a `)` delimiter
        // or a non-decimal format needs a definition of its own.
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
            return true;
        }

        return $start === 1
            && $delimiter === '.'
            && $this->config->getOptions()->orderedListFormat === 'decimal';
    }

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
     * A bullet character for each nesting level; every level is defined, because
     * a nested list refers to level 1 of the same numbering, and a level left out
     * is one Word has no formatting for — which is how a sub-list of bullets
     * ends up numbered.
     */
    private const BULLETS = ["\u{2022}", 'o', "\u{25AA}"];

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
