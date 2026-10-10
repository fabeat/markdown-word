<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

/**
 * The SVG originals behind a picture Word will only take as a raster.
 *
 * Word can hold vector images, but not on their own: the `a:blip` an image lives in
 * has to point at something every reader can draw, so an SVG is written next to a
 * raster of itself and referenced from an extension on the same element. This carries
 * the half that cannot go through PHPWord — the original SVG, and the hash that finds
 * the PNG it belongs to — until {@see \MarkdownWord\Writer\SvgPass} puts the two back
 * together.
 *
 * The PNG is found by its content rather than by its position. PHPWord names media
 * parts as it writes them, so at render time nothing says which `imageN.png` a given
 * picture became, and the same picture used twice is the same bytes twice.
 */
final class SvgAttachmentCollector
{
    /** @var list<array{fallback: string, vector: string}> */
    private array $attachments = [];

    /**
     * @param string $fallback the PNG bytes PHPWord was handed
     * @param string $vector   the SVG those bytes were rendered from
     */
    public function add(string $fallback, string $vector): void
    {
        $this->attachments[] = [
            'fallback' => \sha1($fallback),
            'vector' => $vector,
        ];
    }

    /**
     * @return list<array{fallback: string, vector: string}>
     */
    public function all(): array
    {
        return $this->attachments;
    }

    /**
     * Whether an SVG has been rasterised at all.
     *
     * A separate question from {@see self::all()}, which a writer that cannot
     * reattach the vector never asks. An SVG embedded here was flattened into a
     * raster whatever the format is, and only {@see \MarkdownWord\Writer\SvgPass} can
     * put the vector back — so a writer that never calls it loses something, and
     * {@see \MarkdownWord\Format} has to be able to say so.
     */
    public function anyAttached(): bool
    {
        return $this->attachments !== [];
    }
}