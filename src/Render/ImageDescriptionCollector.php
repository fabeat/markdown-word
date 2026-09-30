<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

/**
 * Collects the alt text of the images added during a render.
 *
 * PHPWord has nowhere to put it — the writer emits an empty `o:title` on every
 * image — so the list is handed to {@see \MarkdownWord\Writer\ImageDescriptionPass},
 * which fills it in once the file has been written. Images are matched in the
 * order they were added, which is the order the renderer walks the syntax tree.
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
     * The alt texts added since the last call, and the start of the next batch.
     *
     * A document is written as soon as its elements are complete, so a batch is
     * one document's images.
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
