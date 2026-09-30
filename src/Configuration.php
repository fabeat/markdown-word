<?php

declare(strict_types=1);

namespace MarkdownWord;

use MarkdownWord\Configuration\Options;
use MarkdownWord\Configuration\Styles;

/**
 * Entry point for configuring the renderer, pairing a {@see Styles} with an
 * {@see Options}. Both can be built from a plain array, which makes it trivial to
 * keep the look of your documents in a `config.php` file.
 */
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
     * Without any of the aesthetic defaults. Everything still lands in the
     * document, it just looks like plain text — which is the point when it is
     * going into a heavily pre-styled template.
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
