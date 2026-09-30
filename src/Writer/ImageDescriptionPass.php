<?php

declare(strict_types=1);

namespace MarkdownWord\Writer;

use DOMDocument;
use DOMElement;
use DOMXPath;
use MarkdownWord\Exception\MalformedDocument;
use MarkdownWord\Exception\UnreadableDocument;
use MarkdownWord\Xml;
use ZipArchive;

/**
 * Fills in the alternative text of the images in an already written `.docx`.
 *
 * PHPWord has no API for it: a document this library writes carries its pictures
 * as VML — `w:pict/v:shape/v:imagedata` — and the writer emits the description
 * of each one as a literal `o:title=""`. A DrawingML picture, which is what a
 * template authored in Word already contains, keeps its description on
 * `wp:docPr` and is handled here for the same reason.
 *
 * The images are matched in document order against the order they were added
 * in, and the DOM is used rather than string replacement so content that happens
 * to look like an image element cannot confuse it.
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

    /**
     * @throws UnreadableDocument When the archive cannot be opened, or has no
     *         document part to rewrite.
     * @throws MalformedDocument When `word/document.xml` is not XML.
     */
    public function applyTo(string $docxPath): void
    {
        $zip = new ZipArchive();

        if ($zip->open($docxPath) !== true) {
            throw new UnreadableDocument(sprintf('Unable to open "%s" as a zip archive.', $docxPath));
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
     * The document part with the descriptions put in, without touching a zip.
     *
     * @throws MalformedDocument When the document part is not XML.
     */
    private function transform(string $documentXml): string
    {
        if ($this->descriptions === []) {
            return $documentXml;
        }

        $dom = Xml::parseOrFail($documentXml, 'word/document.xml is not valid XML.');

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
     * a VML one on the image data. The VML one wins when both are present,
     * because that is what this library writes for an inline picture.
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
