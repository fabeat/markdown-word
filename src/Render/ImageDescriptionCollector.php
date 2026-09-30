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
 * The collector lives for the lifetime of a converter rather than of a document,
 * for the same reason the hyperlink collector does: several documents can be
 * rendered through one converter, as the template renderer does, and their
 * images have to stay in step with it.
 */
final class ImageDescriptionCollector
{
    /** @var list<string> */
    private array $descriptions = [];

    private int $offset = 0;

    /**
     * Record one image's alt text.
     */
    public function add(string $description): void
    {
        $this->descriptions[] = $description;
    }

    /**
     * The alt texts added since the last call, and the start of the next batch.
     *
     * A document is written as soon as its elements are complete, so the
     * descriptions for *that* document are everything recorded since the previous
     * one was taken.
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
