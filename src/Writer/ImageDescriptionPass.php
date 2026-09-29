<?php

declare(strict_types=1);

namespace MarkdownWord\Writer;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Fills in the alternative text of the images in an already written `.docx`.
 *
 * PHPWord has no API for it: the writer emits `o:title` as a literal empty string
 * on every `v:imagedata`, and the `descr` of a DrawingML picture is likewise left
 * blank. An image without alternative text is in the document and its meaning is
 * not, so the text the Markdown supplied is put back here.
 *
 * The images are matched in document order against the order they were added in,
 * which is the same order the renderer walks the syntax tree. Doing it with the
 * DOM rather than by string replacement means content that happens to look like
 * an image element cannot confuse it.
 */
final class ImageDescriptionPass
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const V_NS = 'urn:schemas-microsoft-com:vml';
    private const O_NS = 'urn:schemas-microsoft-com:office:office';
    private const WP_NS = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';

    private const DOCUMENT_PATH = 'word/document.xml';

    /**
     * @param list<string> $descriptions The alt text of each image, in the order
     *        the images were added to the document.
     */
    public function __construct(private readonly array $descriptions)
    {
    }

    public function applyTo(string $docxPath): void
    {
        $zip = new ZipArchive();

        if ($zip->open($docxPath) !== true) {
            throw new RuntimeException(sprintf('Unable to open "%s" as a zip archive.', $docxPath));
        }

        try {
            $document = $zip->getFromName(self::DOCUMENT_PATH);

            if ($document === false) {
                return;
            }

            $updated = $this->transform($document);

            $zip->deleteName(self::DOCUMENT_PATH);
            $zip->addFromString(self::DOCUMENT_PATH, $updated);
        } finally {
            $zip->close();
        }
    }

    /**
     * Exposed for testing: transforms the document part without touching a zip.
     */
    public function transform(string $documentXml): string
    {
        if ($this->descriptions === []) {
            return $documentXml;
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        $loaded = $dom->loadXML($documentXml, LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new RuntimeException('word/document.xml is not valid XML.');
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('v', self::V_NS);
        $xpath->registerNamespace('o', self::O_NS);
        $xpath->registerNamespace('wp', self::WP_NS);

        $index = 0;

        foreach ($this->imageNodes($xpath) as [$node, $attribute, $namespace]) {
            $description = $this->descriptions[$index] ?? null;

            if ($description !== null && $description !== '') {
                $namespace === ''
                    ? $node->setAttribute($attribute, $description)
                    : $node->setAttributeNS($namespace, $attribute, $description);
            }

            $index++;
        }

        return (string) $dom->saveXML();
    }

    /**
     * The elements that carry an image's description, in document order.
     *
     * A DrawingML picture keeps the description on its non-visual properties and
     * a VML one on the image data. A document uses one shape or the other, and
     * the VML one is preferred when both are present because that is what
     * PHPWord writes for an inline picture.
     *
     * @return list<array{0: DOMElement, 1: string, 2: string}>
     */
    private function imageNodes(DOMXPath $xpath): array
    {
        $vml = $xpath->query('//v:imagedata');
        $nodes = [];

        if ($vml !== false && $vml->length > 0) {
            foreach ($vml as $node) {
                $nodes[] = [$node, 'o:title', self::O_NS];
            }

            return $nodes;
        }

        foreach ($xpath->query('//wp:docPr') ?: [] as $node) {
            $nodes[] = [$node, 'descr', ''];
        }

        return $nodes;
    }
}
