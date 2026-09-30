<?php

declare(strict_types=1);

namespace MarkdownWord\Reverse;

/**
 * Turns the block tree back into Markdown.
 *
 * The tree keeps the structure that Word preserved and drops what it did not,
 * so the writer's job is mostly mechanical. The two places it does real work
 * are the escaping of literal text — delegated to {@see Escaping} — and the
 * marker of a list item, which has to reproduce the numbering definition the
 * document carried, including a start value other than one.
 *
 * Nothing here throws: a block tree goes in and Markdown comes out, and every
 * construct the writer can be handed has a string it can be written as. The
 * exceptions a conversion raises come from the package around it — opening one
 * and parsing its parts — so they belong to {@see Package}, which raises
 * {@see \MarkdownWord\Exception\UnreadableDocument} for bytes that are not a zip
 * archive, {@see \MarkdownWord\Exception\MalformedDocument} for a required part
 * that is missing or will not parse, and
 * {@see \MarkdownWord\Exception\FileNotWritable} when the scratch file a package
 * held in memory needs cannot be written. The middle of those three is the one
 * {@see \MarkdownWord\Xml::parseOrFail()} raises in the other direction, when it
 * is asked to load a part of a document it is rewriting rather than reading.
 */
final class MarkdownWriter
{
    /** @var list<int> The number the next ordered item at each open level carries. */
    private array $counters = [];

    public function __construct(private readonly Options $options)
    {
    }

    /**
     * @param list<Block> $blocks
     */
    public function write(array $blocks): string
    {
        return rtrim($this->blocks($blocks), "\n");
    }

    /**
     * The blocks, one after another, separated by the blank line that makes a
     * list loose again after it has been interrupted.
     *
     * The blocks are kept apart until here rather than joined first and tidied
     * afterwards, because the space between two blocks and the space inside one
     * are different things. A blank line inside a verbatim block is content — the
     * gap in a log excerpt, the paragraph break in a fixture — while a run of
     * them between two blocks is only ever a way of writing a blank line.
     * Tidying the finished document could not tell the two apart and lost the
     * first; keeping the blocks apart until they are joined cannot.
     *
     * @param list<Block> $blocks
     * @param string       $separator What goes between the blocks, which is a
     *        single newline in a tight list and a blank line in a loose one.
     */
    private function blocks(array $blocks, string $separator = "\n\n"): string
    {
        $parts = [];

        foreach ($blocks as $block) {
            $part = $this->block($block);

            if ($part !== '') {
                // A block never begins or ends with a line break of its own, and
                // saying so here means the separator alone decides how far apart
                // two blocks stand: whatever the reader left between them, the
                // Markdown has one blank line.
                $parts[] = trim($part, "\n");
            }
        }

        return implode($separator, $parts);
    }

    private function block(Block $block): string
    {
        return match ($block->kind) {
            Block::PARAGRAPH => $this->paragraph($block),
            Block::RULE => '---',
            Block::CODE => $this->code($block),
            Block::LIST => $this->list($block),
            Block::QUOTE => $this->quote($block),
            Block::TABLE => $this->table($block),
            default => '',
        };
    }

    private function paragraph(Block $block): string
    {
        // A newline at either end of a paragraph is an artefact of the block that
        // held it rather than something the reader should see.
        $text = trim($this->inlines($block->inlines), "\n");
        $level = $block->attr('level');

        if (!is_int($level)) {
            return $text;
        }

        if ($this->options->headingSetext && $level <= 2) {
            return $text . "\n" . str_repeat($level === 1 ? '=' : '-', 3);
        }

        return str_repeat('#', min(6, $level)) . ' ' . $this->closeAtx($text);
    }

    /**
     * Keep a trailing run of hashes from becoming an ATX closing sequence.
     *
     * `## foo ##` is a heading whose content is `foo` — the second pair of hashes
     * is swallowed as the closing sequence — so the hash that would be read as one
     * is escaped. A hash that was already escaped in the document is left alone.
     */
    private function closeAtx(string $text): string
    {
        return (string) preg_replace('/(?<!\\\\)(#+)$/', '\\\\$1', $text);
    }

    /**
     * A verbatim block, fenced so that its whitespace survives a reader.
     */
    private function code(Block $block): string
    {
        $text = (string) $block->attr('text', '');
        $info = (string) $block->attr('info', '');

        // A run of backticks in the content has to be shorter than the fence, or
        // it would close the block early. The longest run is found in one pass
        // rather than by looking for three backticks and then four and then five:
        // that is a search of the whole content per backtick, and time quadratic
        // in its length, which a document with a long run in it would feel.
        $length = max(3, Escaping::longestRun($text, '`') + 1);

        $fence = str_repeat('`', $length);

        return $fence . $info . "\n" . $text . "\n" . $fence;
    }

    private function quote(Block $block): string
    {
        $body = $this->blocks($block->children);

        if ($body === '') {
            return '';
        }

        // Every line of the quote is prefixed, including the blank ones, because
        // an unprefixed blank line ends the quote.
        $lines = array_map(
            static fn (string $line): string => $line === '' ? '>' : '> ' . $line,
            explode("\n", $body),
        );

        return implode("\n", $lines);
    }

    private function list(Block $block): string
    {
        $ordered = (bool) $block->attr('ordered', false);
        $start = (int) $block->attr('start', 1);
        $format = (string) $block->attr('format', 'decimal');
        $delimiter = (string) $block->attr('delimiter', '.');
        $marker = $ordered ? ($this->marker($format, $start) . $delimiter) : '-';

        $this->counters[] = $start;

        // A tight list runs its items together; a loose one puts a blank line
        // between them, which is what makes the list loose again.
        $separator = $block->attr('tight', false) === true ? "\n" : "\n\n";
        $lines = [];

        foreach ($block->children as $item) {
            $lines[] = $this->item($item, $marker, $separator);

            if ($ordered) {
                $this->counters[count($this->counters) - 1]++;
                $marker = $this->marker($format, $this->counters[count($this->counters) - 1]) . $delimiter;
            }
        }

        array_pop($this->counters);

        return implode($separator, $lines);
    }

    /**
     * One list item, whose body may be loose — several blocks separated by blank
     * lines — or contain a nested list.
     */
    private function item(Block $item, string $marker, string $separator): string
    {
        $blocks = $item->children;
        $checkbox = $this->checkbox($blocks);

        if ($checkbox !== '') {
            $blocks[0] = $blocks[0]->withInlines($this->withoutCheckbox($blocks[0]->inlines));
        }

        $body = $this->blocks($blocks, $separator);

        if ($body === '') {
            return $marker . ' ' . $checkbox;
        }

        $indent = str_repeat(' ', strlen($marker) + 1);
        $lines = explode("\n", $body);

        // The first line sits next to the marker; every later line is indented
        // to line up with it, which is what keeps a nested list nested.
        $out = $marker . ' ' . $checkbox . $lines[0];

        foreach (array_slice($lines, 1) as $line) {
            $out .= "\n" . ($line === '' ? '' : $indent . $line);
        }

        return $out;
    }

    /**
     * The `[ ] ` or `[x] ` that opens a task list item, if there is one.
     *
     * Word has no checkbox, so a task list item is drawn with the character GFM
     * uses. Putting it back is what keeps such an item a task list item rather
     * than an item that happens to begin with a box.
     *
     * @param list<Block> $blocks
     */
    private function checkbox(array $blocks): string
    {
        $first = $blocks[0] ?? null;

        if ($first === null || !$first->is(Block::PARAGRAPH) || $first->inlines === []) {
            return '';
        }

        $text = $first->inlines[0]->kind === Inline::TEXT ? $first->inlines[0]->text : '';

        // The space after the character is optional: the text that followed the
        // marker in the source usually has one of its own, and it is removed
        // along with the character so the two do not end up side by side.
        return match (true) {
            preg_match('/^\x{2610}\s?/u', $text) === 1 => '[ ] ',
            preg_match('/^\x{2612}\s?/u', $text) === 1 => '[x] ',
            default => '',
        };
    }

    /**
     * @param list<Inline> $inlines
     * @return list<Inline>
     */
    private function withoutCheckbox(array $inlines): array
    {
        $first = array_shift($inlines);

        return [$first->withText((string) preg_replace('/^[\x{2610}\x{2612}]\s?/u', '', $first->text)), ...$inlines];
    }

    /**
     * The numbering a `w:numFmt` value stands for.
     *
     * Markdown spells these as marker text, so a Word list numbered `a, b, c`
     * comes back as `a. b. c.` rather than as a paragraph per item. A format the
     * table does not know is written as the number itself, which is at least
     * something a reader can still make sense of.
     */
    private function marker(string $format, int $number): string
    {
        return match ($format) {
            'lowerLetter' => $this->alphabet($number, false),
            'upperLetter' => $this->alphabet($number, true),
            'lowerRoman' => strtolower($this->roman($number)),
            'upperRoman' => $this->roman($number),
            'decimalZero' => $this->decimalZero($number),
            default => (string) $number,
        };
    }

    /**
     * The letters of a spreadsheet column, which is how Word goes on numbering a
     * list past the twenty-sixth item: `a` to `z`, then `aa`, `ab` and so on.
     *
     * The count has no zero in it, which is what makes the run continue instead
     * of starting again. One off the number is the letter to write, and it is
     * never over `z`, so what is left of the count is written in front of it and
     * the twenty-seventh item is `aa` rather than `a` a second time.
     */
    private function alphabet(int $number, bool $upper): string
    {
        if ($number < 1) {
            return (string) $number;
        }

        $letters = '';

        while ($number > 0) {
            $letters = chr(ord('a') + ($number - 1) % 26) . $letters;
            $number = intdiv($number - 1, 26);
        }

        return $upper ? strtoupper($letters) : $letters;
    }

    /**
     * A number padded to two digits, which is the whole of what `decimalZero`
     * means. Past ninety-nine there is nothing left to pad to, and Word writes
     * the number out as it stands.
     */
    private function decimalZero(int $number): string
    {
        return str_pad((string) $number, 2, '0', STR_PAD_LEFT);
    }

    /**
     * A Roman numeral, which carries on past fifty as readily as `LI` and `LII`.
     * There is no numeral for a number above three thousand nine hundred and
     * ninety-nine, so past that the number itself is written.
     */
    private function roman(int $number): string
    {
        if ($number < 1 || $number > 3999) {
            return (string) $number;
        }

        $values = [
            'M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400, 'C' => 100,
            'XC' => 90, 'L' => 50, 'XL' => 40, 'X' => 10, 'IX' => 9,
            'V' => 5, 'IV' => 4, 'I' => 1,
        ];

        $roman = '';
        foreach ($values as $letters => $value) {
            while ($number >= $value) {
                $roman .= $letters;
                $number -= $value;
            }
        }

        return $roman;
    }

    private function table(Block $block): string
    {
        /** @var list<Block> $rows */
        $rows = $block->children;
        $alignments = (array) $block->attr('alignments', []);

        if ($rows === []) {
            return '';
        }

        $cells = array_map(
            fn (Block $row): array => array_map($this->cell(...), $row->children),
            $rows,
        );

        $width = max(array_map('count', $cells));

        // GFM tables always have a header row and a delimiter row. A Word table
        // records neither: the delimiter row is a row of dashes Markdown invents
        // and there is nothing in the document to derive it from, and a header is
        // marked with `w:trPr/w:tblHeader`, which both PHPWord and Word write and
        // the reader here does not look for. So the first row becomes the header
        // on the assumption that is usually right.
        [$header, $body] = $this->options->tableHeader
            ? [array_shift($cells), $cells]
            : [[], $cells];

        if ($header === []) {
            $header = array_fill(0, $width, '');
        }

        $lines = [
            $this->tableRow($header, $width),
            $this->delimiterRow($alignments, $width),
        ];

        foreach ($body as $row) {
            $lines[] = $this->tableRow($row, $width);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $cells
     */
    private function tableRow(array $cells, int $width): string
    {
        $padded = array_pad($cells, $width, '');

        return '| ' . implode(' | ', $padded) . ' |';
    }

    /**
     * @param list<string> $alignments
     */
    private function delimiterRow(array $alignments, int $width): string
    {
        $markers = [];

        for ($index = 0; $index < $width; $index++) {
            $markers[] = match ($alignments[$index] ?? '') {
                'center' => ':-:',
                'right' => '--:',
                default => ':--',
            };
        }

        return '| ' . implode(' | ', $markers) . ' |';
    }

    /**
     * A cell holds blocks; GFM holds one line, so a paragraph break becomes the
     * one way of writing more than one line in a cell.
     */
    private function cell(Block $cell): string
    {
        $parts = [];

        foreach ($cell->children as $block) {
            $text = $this->block($block);

            if ($text !== '') {
                $parts[] = str_replace("\n", '<br>', $text);
            }
        }

        return implode('<br>', $parts);
    }

    // ----------------------------------------------------------------- inlines

    /**
     * Write a run of inlines as Markdown.
     *
     * The emphasis delimiters are held open across runs rather than opened and
     * closed one run at a time. A bold run followed by a bold italic one then
     * comes out as `**a*b***`: the `**` opened by the first run is still open
     * when the second one adds its `*`, so the two spans meet rather than
     * beginning and ending twice over. Closing each run on its own would give
     * `**a*****b***`, where the run of five asterisks in the middle is ambiguous
     * and a parser may not agree on where the spans start and stop.
     *
     * @param list<Inline> $inlines
     * @param bool         $inTable  Whether the runs sit inside a table cell,
     *        where a pipe would end the cell.
     */
    private function inlines(array $inlines, bool $inTable = false): string
    {
        $out = '';
        $open = [];
        $lineStart = true;

        $runs = $this->coalesce($inlines);

        // A break at either end of a paragraph says nothing: it is the boundary
        // between one paragraph and the next, not a line break inside one.
        while ($runs !== [] && $runs[0]->is(Inline::BREAK)) {
            array_shift($runs);
        }

        while ($runs !== [] && $runs[count($runs) - 1]->is(Inline::BREAK)) {
            array_pop($runs);
        }

        foreach ($runs as $inline) {
            if ($inline->is(Inline::BREAK)) {
                $out = $this->closeAll($out, $open);
                $out .= "  \n";
                $lineStart = true;

                continue;
            }

            if ($inline->is(Inline::LINK, Inline::IMAGE)) {
                $out = $this->closeAll($out, $open);

                // `![` is an image, so an exclamation mark written straight
                // before a link has to be escaped. The document said "!foo"; the
                // Markdown must not say "an image of foo".
                if ($out !== '' && str_ends_with($out, '!')) {
                    $out = substr($out, 0, -1) . '\\!';
                }

                $out .= $inline->is(Inline::LINK) ? $this->link($inline) : $this->image($inline);
                $lineStart = false;

                continue;
            }

            $out = $this->emphasis($out, $open, $inline, $lineStart, $inTable);
            $lineStart = false;
        }

        return $this->closeAll($out, $open);
    }

    /**
     * Write one text run, keeping open any emphasis the neighbouring runs share.
     *
     * @param string         $out       Everything written before this run.
     * @param list<string>   $open      The delimiters still open around it,
     *        outermost first, which this run may close, add to or leave alone.
     * @param Inline         $inline    The run to write.
     * @param bool           $lineStart Whether the run begins a line, where a
     *        `#`, a `-` or a `1.` would otherwise start a block.
     * @param bool           $inTable  Whether the run sits inside a table cell,
     *        where a pipe would end the cell.
     */
    private function emphasis(string $out, array &$open, Inline $inline, bool $lineStart, bool $inTable): string
    {
        if ($inline->code) {
            // A code span is verbatim, so its content is written as it stands:
            // escaping inside it would make the backslashes visible. Where no
            // code span can hold the content, the characters are written out.
            $span = Escaping::codeSpan($inline->text);

            return $out . ($span ?? $this->hardenNewlines(
                Escaping::text($inline->text, $lineStart, $inTable),
            ));
        }

        $wanted = $this->markers($inline);

        // Drop the markers this run does not carry, innermost first, so that
        // what stays open is a prefix of what the run wants.
        foreach (array_reverse(array_keys($open), true) as $index) {
            if (!in_array($open[$index], $wanted, true)) {
                $out = $this->close($out, $open[$index]);
                unset($open[$index]);
            }
        }

        $open = array_values($open);

        $text = $this->hardenNewlines(Escaping::text($inline->text, $lineStart, $inTable));

        // A delimiter has to sit against the text it delimits. Whitespace that
        // opens or closes a run goes outside the markers, because a `*` followed
        // by a space cannot open anything and a `*` preceded by one cannot close
        // anything. Writing `*foo* ` rather than `*foo *` is both correct and what
        // a person would have written.
        $leading = '';
        if ($text !== '' && preg_match('/^(\s+)(.*)$/s', $text, $matches) === 1) {
            [$leading, $text] = [$matches[1], $matches[2]];
        }

        $out .= $leading;

        foreach ($wanted as $marker) {
            if (!in_array($marker, $open, true)) {
                $out .= $marker;
                $open[] = $marker;
            }
        }

        // A run that is nothing but whitespace has nothing to emphasise, and
        // delimiters around it would not parse.
        return $text === '' ? $out : $out . $text;
    }

    /**
     * Turn the newlines inside a run into hard line breaks.
     *
     * A newline in a run's text is a line break Word held inside one paragraph,
     * not a boundary between two. A hard break is what keeps it a line break on
     * the way back; a soft one would collapse to a space and quietly join two
     * lines of a `<pre>` block into one.
     */
    private function hardenNewlines(string $text): string
    {
        if (!str_contains($text, "\n")) {
            return $text;
        }

        return implode("  \n", explode("\n", str_replace("\r", '', $text)));
    }

    /**
     * The delimiters a run's formatting calls for, outermost first.
     *
     * @return list<string>
     */
    private function markers(Inline $inline): array    {
        $markers = [];

        if ($inline->strike) {
            $markers[] = '~~';
        }

        if ($inline->bold) {
            $markers[] = '**';
        }

        if ($inline->italic) {
            $markers[] = '*';
        }

        return $markers;
    }

    /**
     * Close an open delimiter, stepping over any whitespace that would stop it
     * from closing.
     *
     * @return string
     */
    private function close(string $out, string $marker)
    {
        if (preg_match('/^(.*?)(\s*)$/s', $out, $matches) === 1 && $matches[2] !== '') {
            return $matches[1] . $marker . $matches[2];
        }

        return $out . $marker;
    }

    /**
     * @param list<string> $open
     */
    private function closeAll(string $out, array &$open): string
    {
        while ($open !== []) {
            $out = $this->close($out, array_pop($open));
        }

        return $out;
    }

    private function image(Inline $inline): string
    {
        $alt = Escaping::text($inline->alt, lineStart: false, inTable: true);
        $target = Escaping::text($inline->target, lineStart: false, inTable: true);

        // A destination holding whitespace, a parenthesis or an angle bracket has
        // to be put in angle brackets, which is the only form that can carry one.
        if (preg_match('/[\s()<>]/', $target) === 1) {
            $target = '<' . str_replace(['<', '>'], ['\\<', '\\>'], $target) . '>';
        }

        return '![' . $alt . '](' . $target . ')';
    }

    private function link(Inline $inline): string
    {
        $label = $this->inlines($inline->children);

        $url = $inline->url;

        if (preg_match('/[\s()<>]/', $url) === 1) {
            $url = '<' . str_replace(['<', '>'], ['\\<', '\\>'], $url) . '>';
        }

        if ($inline->title !== null && $inline->title !== '') {
            $url .= ' "' . str_replace('"', '\\"', $inline->title) . '"';
        }

        return '[' . $label . '](' . $url . ')';
    }

    /**
     * Join neighbouring runs that differ only in emphasis, so that a paragraph
     * split into fifty runs by Word comes out as a handful of spans.
     *
     * @param list<Inline> $inlines
     * @return list<Inline>
     */
    private function coalesce(array $inlines): array
    {
        $out = [];

        foreach ($inlines as $inline) {
            $last = $out === [] ? null : $out[count($out) - 1];

            if (
                $last !== null
                && $last->is(Inline::TEXT)
                && $inline->is(Inline::TEXT)
                && $last->sameFormatting($inline)
            ) {
                $out[count($out) - 1] = $last->withText($last->text . $inline->text);

                continue;
            }

            $out[] = $inline;
        }

        return $out;
    }
}
