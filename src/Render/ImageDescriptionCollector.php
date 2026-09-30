<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

/**
 * Collects the alt text of the images added during a render.
 *
 * PHPWord has nowhere to put it — the writer emits an empty `o:title` on every
 * image — so {@see \MarkdownWord\Writer\ImageDescriptionPass} fills it in once
 * the file is written, matching the images in the order the renderer walked the
 * syntax tree.
 *
 * The collector outlives a single document, as the hyperlink collector does, so
 * several documents rendered through one converter stay in step.
 */
final class ImageDescriptionCollector
{
    /** @var list<string> */
    private array $descriptions = [];

    private int $offset = 0;

    public function add(string $description): void
    {
        $this->descriptions[] = $description;
    }

    /**
     * One document's images: the document is written as soon as its elements are
     * complete, so a batch ends there.
     *
     * @return list<string>
     */
    public function take(): array
    {
        $batch = array_slice($this->descriptions, $this->offset);
        $this->offset = count($this->descriptions);

        return $batch;
    }
}
