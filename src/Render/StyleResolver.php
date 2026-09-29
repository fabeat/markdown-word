<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Styles;
use PhpOffice\PhpWord\Style\Font;

/**
 * Translates renderer concerns into concrete Word styles.
 *
 * Keeping this in one place means the rest of the renderer never has to think
 * about style names, defaults or the "does the template already define this?"
 * question.
 */
final class StyleResolver
{
    public function __construct(private readonly Configuration $config)
    {
    }

    public function styles(): Styles
    {
        return $this->config->getStyles();
    }

    /**
     * The raw value configured for a slot, whether it is a Word style name, an
     * inline style array, or nothing at all.
     */
    public function slot(string $name): mixed
    {
        return $this->styles()->get($name);
    }

    /**
     * The font style (an inline array, or the name of a registered style) for a
     * run with the given inline formatting.
     *
     * @return array<string, mixed>|string|null
     */
    public function fontFor(InlineStyle $style): array|string|null
    {
        $font = [];

        if ($style->bold) {
            $font['bold'] = true;
        }

        if ($style->italic) {
            $font['italic'] = true;
        }

        if ($style->strikethrough) {
            $font['strikethrough'] = true;
        }

        $slots = [];

        if ($style->code) {
            $slots[] = Styles::CODE_FONT;
        }

        if ($style->isLink()) {
            $slots[] = Styles::LINK_FONT;
        }

        // Properties forced by the surrounding block win over the Markdown's own
        // emphasis, so a bold table header stays bold even around italic text.
        if ($style->forcedFont !== []) {
            $font = array_merge($font, $this->normalizeFontArray($style->forcedFont));
        }

        // A slot configured as a plain string names a style from the target
        // document (typically one defined in your Word template). It can only be
        // used verbatim when it is the *only* styling on the run; otherwise we
        // fall back to the built-in array so the combination still resolves.
        $namedOnly = [];
        foreach ($slots as $slot) {
            $value = $this->styles()->get($slot);
            if (is_string($value)) {
                $namedOnly[] = $value;
                continue;
            }
            if (is_array($value)) {
                $font = array_merge($font, $this->normalizeFontArray($value));
            }
        }

        if ($font !== []) {
            return $font;
        }

        if ($namedOnly !== []) {
            return count($namedOnly) === 1 ? $namedOnly[0] : null;
        }

        return null;
    }

    /**
     * The paragraph style for a block, or null for "no styling".
     */
    public function paragraphStyleFor(string $slot): string|array|null
    {
        $value = $this->styles()->get($slot);

        return is_string($value) || is_array($value) || $value === null ? $value : null;
    }

    public function headingStyle(int $level): string|array|null
    {
        $value = $this->styles()->heading($level);

        return is_string($value) || is_array($value) ? $value : null;
    }

    public function softBreakMode(): string
    {
        return $this->config->getOptions()->softBreak;
    }

    public function hardBreakMode(): string
    {
        return $this->config->getOptions()->hardBreak;
    }

    public function isInternalLink(string $url): bool
    {
        $target = $this->config->getOptions()->linkTarget;

        return $target === '_self' || str_starts_with($url, '#');
    }

    /**
     * Turn the loose `color`/shading shorthand used in the default style
     * definitions into the exact keys PHPWord's Font style understands.
     *
     * @param  array<string, mixed>  $font
     * @return array<string, mixed>
     */
    private function normalizeFontArray(array $font): array
    {
        if (isset($font['shading']) && !isset($font['bgColor'])) {
            $font['bgColor'] = $font['shading'];
        }
        unset($font['shading']);

        return $font;
    }

    /**
     * Build a Font style object, used for code block shading.
     */
    public static function font(array $definition): Font
    {
        $font = new Font();
        $font->setStyleByArray($definition);

        return $font;
    }
}
