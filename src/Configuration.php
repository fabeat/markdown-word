<?php

declare(strict_types=1);

namespace MarkdownWord;

use MarkdownWord\Configuration\Options;
use MarkdownWord\Configuration\Styles;

final class Configuration
{
    public function __construct(
        private readonly Styles $styles = new Styles(),
        private readonly Options $options = new Options(),
    ) {
    }

    public static function create(): self
    {
        return new self();
    }

    /**
     * @param array{styles?: array<string, mixed>, options?: array<string, mixed>} $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            isset($config['styles']) ? new Styles($config['styles']) : new Styles(),
            isset($config['options']) ? Options::fromArray($config['options']) : new Options(),
        );
    }

    public function getStyles(): Styles
    {
        return $this->styles;
    }

    public function getOptions(): Options
    {
        return $this->options;
    }

    public function withStyles(Styles|array $styles): self
    {
        return new self(
            $styles instanceof Styles ? $styles : $this->styles->withAll($styles),
            $this->options,
        );
    }

    public function withOptions(Options|array $options): self
    {
        return new self(
            $this->styles,
            $options instanceof Options ? $options : $this->options->withAll($options),
        );
    }

    public function withBuiltInHeadingStyles(): self
    {
        $headings = [];
        for ($level = 1; $level <= 6; $level++) {
            $headings['heading.' . $level] = 'Heading' . $level;
        }

        return $this->withStyles($headings + [
            Styles::BLOCK_QUOTE => 'Quote',
            Styles::BULLET_LIST => 'ListBullet',
            Styles::ORDERED_LIST => 'ListNumber',
        ]);
    }

    /**
     * Turns off what this library adds over Word's own styles: the code and link
     * fonts, the quote style, table borders and code-block shading. Everything
     * still lands in the document, which is the point when a template governs
     * the look.
     */
    public function withoutDecoration(): self
    {
        return $this->withStyles([
            Styles::CODE_FONT => null,
            Styles::LINK_FONT => null,
            Styles::BLOCK_QUOTE => null,
        ])->withOptions([
            'tableBorders' => false,
            'codeBlockShading' => false,
        ]);
    }

    /**
     * @return array{styles: array<string, mixed>, options: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'styles' => $this->styles->toArray(),
            'options' => $this->options->toArray(),
        ];
    }
}
