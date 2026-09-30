<?php

declare(strict_types=1);

namespace MarkdownWord\Writer;

use DOMDocument;
use DOMElement;
use MarkdownWord\Exception\FileNotWritable;
use MarkdownWord\Exception\MalformedDocument;
use MarkdownWord\Exception\UnsupportedElement;
use MarkdownWord\Xml;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\XMLWriter;
use ZipArchive;

/**
 * Carries Word list numbering across from a scratch document into a template.
 *
 * When rendered elements are copied into another document, a list paragraph
 * still points at the numbering definition it had where it was created. A
 * template has its own `word/numbering.xml`, so those references resolve to
 * nothing and the lists silently lose their bullets.
 *
 * This assigns the definitions fresh identifiers in the template and rewrites
 * the references to match.
 */
final class NumberingMerger
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const NUMBERING_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering';
    private const NUMBERING_CT = 'application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml';

    /**
     * Scratch `w:numId` => template `w:numId`.
     *
     * @var array<int, int>
     */
    private array $numIdMap = [];

    /**
     * Scratch `w:abstractNumId` => the definition to append to the template.
     *
     * @var array<int, DOMElement>
     */
    private array $definitions = [];

    /**
     * Scratch `w:numId` => the `w:abstractNumId` it now points at.
     *
     * @var array<int, int>
     */
    private array $abstractForNum = [];

    private int $nextNumId = 1;

    private int $nextAbstractId = 0;

    public function __construct(private readonly PhpWord $scratch)
    {
    }

    /**
     * Read the numbering definitions the scratch document currently holds.
     *
     * Safe to call after every render: definitions already taken are skipped, so
     * only the ones added since the last call are mapped.
     */
    public function collect(): void
    {
        $numbering = $this->readScratchNumbering();

        if ($numbering === null) {
            return;
        }

        $xpath = new \DOMXPath($numbering);
        $xpath->registerNamespace('w', self::W_NS);

        $abstracts = [];
        foreach ($xpath->query('//w:abstractNum') ?: [] as $abstract) {
            $abstracts[$abstract->getAttribute('w:abstractNumId')] = $abstract;
        }

        foreach ($xpath->query('//w:num') ?: [] as $num) {
            $scratchId = (int) $num->getAttribute('w:numId');

            if (isset($this->numIdMap[$scratchId])) {
                continue;
            }

            $abstractId = $this->childValue($num, 'abstractNumId');

            // The definition travels with the reference; without it the new
            // numId would point at nothing.
            if ($abstractId !== null && isset($abstracts[$abstractId])) {
                $newId = $this->nextAbstractId++;
                $this->definitions[$abstractId] = $this->withAbstractId($abstracts[$abstractId], $newId);
                $this->abstractForNum[$scratchId] = $newId;
            }

            $this->numIdMap[$scratchId] = $this->nextNumId++;
        }
    }

    /**
     * Render one element to the XML the template should contain, with its
     * numbering references already pointing at this merger.
     */
    public function renderElement(AbstractElement $element): string
    {
        $writerClass = 'PhpOffice\\PhpWord\\Writer\\Word2007\\Element\\' . $this->shortName($element);

        if (!class_exists($writerClass)) {
            throw new UnsupportedElement(sprintf(
                'PHPWord has no Word 2007 writer for the element "%s".',
                $element::class,
            ));
        }

        $xmlWriter = new XMLWriter();

        // The element writers consult PHPWord's global escaping setting, which is
        // off by default; without this a run containing `<` or `&` would be
        // written as raw markup.
        Escaping::enabled(function () use ($writerClass, $xmlWriter, $element): void {
            $elementWriter = new $writerClass($xmlWriter, $element, false);
            $elementWriter->write();
        });

        return $this->remap($xmlWriter->getData());
    }

    /**
     * Rewrite the numbering references in a fragment of `word/document.xml`.
     */
    public function remap(string $xml): string
    {
        if ($this->numIdMap === [] || !str_contains($xml, 'w:numId')) {
            return $xml;
        }

        // Wrapped in a root carrying the namespace, so that a fragment using the
        // `w:` prefix parses on its own.
        $dom = Xml::parse('<w xmlns:w="' . self::W_NS . '">' . $xml . '</w>');

        if ($dom === null) {
            return $xml;
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', self::W_NS);

        foreach ($xpath->query('//w:numId') ?: [] as $node) {
            $scratchId = (int) $node->getAttribute('w:val');

            if (isset($this->numIdMap[$scratchId])) {
                $node->setAttribute('w:val', (string) $this->numIdMap[$scratchId]);
            }
        }

        $out = '';
        foreach ($dom->documentElement?->childNodes ?? [] as $child) {
            $out .= $dom->saveXML($child);
        }

        return $out;
    }

    public function hasNumbering(): bool
    {
        return $this->definitions !== [];
    }

    /**
     * Write the collected definitions into a finished `.docx`, creating the
     * numbering part when the template did not have one.
     */
    public function applyTo(string $docxPath): void
    {
        if ($this->definitions === []) {
            return;
        }

        $zip = new ZipArchive();

        if ($zip->open($docxPath) !== true) {
            throw new FileNotWritable(sprintf('Unable to open "%s" for writing.', $docxPath));
        }

        try {
            $numbering = $this->numberingPart($zip);
            $this->appendDefinitions($numbering);

            $zip->deleteName('word/numbering.xml');
            $zip->addFromString('word/numbering.xml', (string) $numbering->saveXML());

            $this->declareContentType($zip);
            $this->declareRelationship($zip);
        } finally {
            $zip->close();
        }
    }

    /**
     * OOXML requires every `w:abstractNum` to come before the `w:num` elements,
     * so the existing ones are lifted out and put back afterwards.
     */
    private function appendDefinitions(DOMDocument $numbering): void
    {
        $root = $numbering->documentElement;

        if ($root === null) {
            return;
        }

        $existingNums = [];

        foreach (iterator_to_array($root->getElementsByTagNameNS(self::W_NS, 'num')) as $node) {
            $existingNums[] = $node;
            $node->parentNode?->removeChild($node);
        }

        foreach ($this->definitions as $definition) {
            $root->appendChild($numbering->importNode($definition, true));
        }

        foreach ($this->numIdMap as $scratchId => $templateId) {
            $num = $numbering->createElementNS(self::W_NS, 'w:num');
            $num->setAttribute('w:numId', (string) $templateId);

            $abstract = $numbering->createElementNS(self::W_NS, 'w:abstractNumId');
            $abstract->setAttribute('w:val', (string) ($this->abstractForNum[$scratchId] ?? 0));
            $num->appendChild($abstract);

            $root->appendChild($num);
        }

        foreach ($existingNums as $node) {
            $root->appendChild($node);
        }
    }

    private function numberingPart(ZipArchive $zip): DOMDocument
    {
        $existing = $zip->getFromName('word/numbering.xml');

        if ($existing === false) {
            $dom = new DOMDocument();
            $dom->appendChild($dom->createElementNS(self::W_NS, 'w:numbering'));

            return $dom;
        }

        $dom = new DOMDocument();
        $this->load($dom, $existing);

        // Continue the identifier sequences the template already uses, so the
        // definitions being added cannot collide with its own.
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', self::W_NS);

        foreach ($xpath->query('//w:num') ?: [] as $node) {
            $this->nextNumId = max($this->nextNumId, (int) $node->getAttribute('w:numId') + 1);
        }

        foreach ($xpath->query('//w:abstractNum') ?: [] as $node) {
            $this->nextAbstractId = max($this->nextAbstractId, (int) $node->getAttribute('w:abstractNumId') + 1);
        }

        return $dom;
    }

    /**
     * Announce the numbering part in `[Content_Types].xml`, unless the document
     * already does.
     */
    private function declareContentType(ZipArchive $zip): void
    {
        $xml = $zip->getFromName('[Content_Types].xml');

        if ($xml === false) {
            return;
        }

        $dom = new DOMDocument();
        $this->load($dom, $xml);

        foreach ($dom->getElementsByTagName('Override') as $override) {
            if ($override instanceof DOMElement && $override->getAttribute('PartName') === '/word/numbering.xml') {
                return;
            }
        }

        $override = $dom->createElement('Override');
        $override->setAttribute('PartName', '/word/numbering.xml');
        $override->setAttribute('ContentType', self::NUMBERING_CT);
        $dom->documentElement?->appendChild($override);

        $zip->deleteName('[Content_Types].xml');
        $zip->addFromString('[Content_Types].xml', (string) $dom->saveXML());
    }

    /**
     * Relate `word/numbering.xml` to the document, unless it already is.
     */
    private function declareRelationship(ZipArchive $zip): void
    {
        $xml = $zip->getFromName('word/_rels/document.xml.rels');

        if ($xml === false) {
            return;
        }

        $dom = new DOMDocument();
        $this->load($dom, $xml);

        $used = [];

        foreach ($dom->getElementsByTagName('Relationship') as $relationship) {
            if (!$relationship instanceof DOMElement) {
                continue;
            }

            $used[] = $relationship->getAttribute('Id');

            if ($relationship->getAttribute('Target') === 'numbering.xml') {
                return;
            }
        }

        $id = 1;
        while (in_array('rId' . $id, $used, true)) {
            $id++;
        }

        $relationship = $dom->createElementNS(self::REL_NS, 'Relationship');
        $relationship->setAttribute('Id', 'rId' . $id);
        $relationship->setAttribute('Type', self::NUMBERING_REL);
        $relationship->setAttribute('Target', 'numbering.xml');
        $dom->documentElement?->appendChild($relationship);

        $zip->deleteName('word/_rels/document.xml.rels');
        $zip->addFromString('word/_rels/document.xml.rels', (string) $dom->saveXML());
    }

    private function withAbstractId(DOMElement $abstract, int $id): DOMElement
    {
        $clone = $abstract->cloneNode(true);
        $clone->setAttribute('w:abstractNumId', (string) $id);

        return $clone;
    }

    private function readScratchNumbering(): ?DOMDocument
    {
        $path = tempnam(sys_get_temp_dir(), 'mdword_num_');

        if ($path === false) {
            throw new FileNotWritable('Unable to create a temporary file for the numbering part.');
        }

        try {
            Escaping::enabled(function () use ($path): void {
                IOFactory::createWriter($this->scratch, 'Word2007')->save($path);
            });

            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                return null;
            }

            $xml = $zip->getFromName('word/numbering.xml');
            $zip->close();
        } finally {
            @unlink($path);
        }

        if ($xml === false) {
            return null;
        }

        $dom = new DOMDocument();
        $this->load($dom, $xml);

        return $dom;
    }

    private function childValue(DOMElement $parent, string $localName): ?string
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                return $child->getAttribute('w:val');
            }
        }

        return null;
    }

    private function load(DOMDocument $dom, string $xml): void
    {
        Xml::parse($xml, flags: 0, into: $dom)
            ?? throw new MalformedDocument('The document contains invalid XML.');
    }

    private function shortName(AbstractElement $element): string
    {
        $class = $element::class;

        return substr($class, (int) strrpos($class, '\\') + 1);
    }
}
