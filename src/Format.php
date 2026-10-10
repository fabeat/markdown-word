<?php

declare(strict_types=1);

namespace MarkdownWord;

use MarkdownWord\Writer\Survey;

/**
 * One of the document formats a conversion can be asked for.
 *
 * `.docx` is the first case and the default everywhere: {@see MarkdownToWord::toDocx()},
 * {@see Converter::convert()} and the command line all name it when nobody says
 * otherwise. The other two are writers PHPWord already ships, exposed with the same
 * staging guarantee and — because they carry less — with an account of what they drop.
 */
enum Format: string
{
    case Docx = 'docx';
    case Odt = 'odt';
    case Rtf = 'rtf';

    /**
     * What a format's writer is called in PHPWord's `IOFactory`. The names are the
     * library's, not ours, and this is the only place the two halves of the question
     * meet: a format that is not a case here has no writer.
     */
    public function writer(): string
    {
        return match ($this) {
            self::Docx => 'Word2007',
            self::Odt => 'ODText',
            self::Rtf => 'RTF',
        };
    }

    /** The extension a result of this format carries, dot included. */
    public function extension(): string
    {
        return '.' . $this->value;
    }

    /**
     * The features this writer cannot express, whatever the document in front of it.
     *
     * A feature that is not on this list is carried; {@see self::carries()} is the
     * other half of the same answer.
     *
     * @return list<string>
     */
    public function drops(): array
    {
        return match ($this) {
            self::Docx => [],
            // `Writer\ODText\Style\Numbering` writes `text:list-level-style-bullet`
            // whatever a level's format is, so an ordered list comes out bulleted;
            // and neither `Writer\ODText\Element\Table` nor
            // `Writer\ODText\Style\Paragraph` writes a border or a background.
            self::Odt => [
                'numbered-lists',
                'table-borders',
                'cell-emphasis',
                'shading',
                'paragraph-border',
            ],
            // `Writer\RTF\Element\AbstractElement::writeOpening()` wants a
            // `Style\Paragraph` and this library's named styles are `Style\Font`;
            // `Element\ListItemRun` has no RTF writer at all; and
            // `Writer\RTF\Part\Header::registerFont()` only walks section-level
            // elements, so a run's typeface never reaches `\fonttbl`.
            self::Rtf => [
                'named-styles',
                'font-face',
                'lists',
                'shading',
                'paragraph-border',
                'image-alt-text',
                'jpeg-label',
            ],
        };
    }

    /**
     * What this writer can express, as the feature keys {@see Survey} asks about.
     *
     * @return list<string>
     */
    public function carries(): array
    {
        return array_values(array_diff(array_keys(self::FEATURES), $this->drops()));
    }

    /**
     * What this writer drops from the document in front of it, in the order the
     * features are declared.
     *
     * @return list<Loss>
     */
    public function losses(Survey $survey): array
    {
        $dropped = array_flip($this->drops());
        $losses = [];

        foreach (self::FEATURES as $feature => $sentence) {
            if (isset($dropped[$feature]) && $survey->uses($feature)) {
                $losses[] = new Loss($feature, $sentence);
            }
        }

        return $losses;
    }

    /**
     * Every feature there is, and what a writer that cannot do it loses. The sentence
     * is written for whoever opens the finished document rather than for whoever
     * wrote the code, because that is the reader who has to act on it.
     *
     * Every key here is a question {@see Survey} answers, and `tests/Unit/format.php`
     * holds the two lists together: a feature nobody asks about is reported for every
     * document, and a key that is not here is a loss that is never reported at all.
     *
     * `numbered-lists` is never dropped alongside `lists`: a writer that leaves the
     * items out has lost the numbering with them, and saying both is the same fact
     * twice.
     *
     * @var array<string, string>
     */
    private const FEATURES = [
        'named-styles' => 'headings and other named styles are written as body text',
        'font-face' => 'a run keeps its size and its weight but loses its typeface and colour',
        'lists' => 'every list item is left out of the document',
        'numbered-lists' => 'an ordered list comes out with a bullet in front of each item',
        'table-borders' => 'a table comes out with no borders',
        'cell-alignment' => "a table column's alignment is dropped",
        'cell-emphasis' => 'a table header row is no longer bold',
        'shading' => 'the background of a paragraph is dropped',
        'paragraph-border' => 'the rule under a thematic break is dropped',
        'image-alt-text' => 'a picture is left with no alternative text',
        'jpeg-label' => 'a JPEG is written into the file labelled as a PNG',
    ];
}