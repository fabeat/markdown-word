<?php

declare(strict_types=1);

use MarkdownWord\Render\TableLayout;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\PhpWord;

/*
 * The widths a table is given, and why they are not optional.
 *
 * A Markdown table carries no widths — GFM's delimiter row says alignment and
 * nothing else — so the widths are invented here and written into the document.
 * Word's own invention, given an empty `w:tblGrid` and no `w:tcW`, is to narrow
 * every column to one or two characters. These tests pin the arithmetic that
 * stops it doing that; the element and XML sides are in `table-render.php` and
 * `docx-output.php`.
 */

/** A4 with an inch of margin, which is what a `PhpWord` document gets by default. */
const TEXT_COLUMN = 9026;

it('gives every column a width out of the text column', function () {
    $widths = (new TableLayout(section()))->columnWidths([10, 10]);

    expect($widths)->toHaveCount(2)
        ->and(array_sum($widths))->toBe(TEXT_COLUMN);
});

it('shares the width out in proportion to the content', function () {
    // A table whose second column holds paragraphs, and whose first holds a word:
    // the second wants most of the width, and the first still has to be readable.
    $widths = (new TableLayout(section()))->columnWidths([4, 500]);

    expect($widths[1])->toBeGreaterThan($widths[0])
        ->and($widths[0])->toBeGreaterThanOrEqual(720)
        ->and(array_sum($widths))->toBe(TEXT_COLUMN);
});

it('leaves no column one character wide', function () {
    // The complaint this exists for. A purely proportional split gives a
    // four-character column a few percent of the width of a column holding a
    // paragraph, which is where "one character wide" came from.
    $widths = (new TableLayout(section()))->columnWidths([1, 1000]);

    expect($widths[0])->toBeGreaterThanOrEqual(720);
});

it('divides evenly between columns of the same width', function () {
    $widths = (new TableLayout(section()))->columnWidths([12, 12, 12]);

    // The total has to be the text column exactly, and 9026 is not divisible by
    // three, so "evenly" can only mean within a twip of it. Handing the shortfall
    // out one apiece is what holds that; giving it all to one column does not.
    expect(array_sum($widths))->toBe(TEXT_COLUMN)
        ->and(max($widths) - min($widths))->toBeLessThanOrEqual(1);
});

it('widens a column no further than the cap says', function () {
    // One cell holding a paragraph must not take the table away from its
    // neighbours, however much longer it is than they are.
    $withCap = (new TableLayout(section()))->columnWidths([4, 1000]);
    $muchLonger = (new TableLayout(section()))->columnWidths([4, 100000]);

    expect($muchLonger)->toBe($withCap);
});

it('shrinks the minimum rather than overrun the width', function () {
    // More columns than the minimum can fit: the floors have to give way, or the
    // columns come out wider than the text column between them.
    $widths = (new TableLayout(section()))->columnWidths(array_fill(0, 30, 5));

    expect($widths)->toHaveCount(30)
        ->and(array_sum($widths))->toBe(TEXT_COLUMN)
        ->and(min($widths))->toBeGreaterThan(0);
});

it('gives a table of one column the lot', function () {
    expect((new TableLayout(section()))->columnWidths([200]))->toBe([TEXT_COLUMN]);
});

it('has no widths to give a table of no columns', function () {
    expect((new TableLayout(section()))->columnWidths([]))->toBe([]);
});

it('follows the page the table is on', function () {
    $section = section(['orientation' => 'landscape']);

    expect(array_sum((new TableLayout($section))->columnWidths([10, 10])))
        ->toBe(16838 - 2880);
});

it('follows the margins the table is set in', function () {
    // US Letter, two inches of margin on each side: 12240 − 5760.
    $section = section(['pageSizeW' => 12240, 'marginLeft' => 2880, 'marginRight' => 2880]);

    expect(array_sum((new TableLayout($section))->columnWidths([10, 10])))->toBe(6480);
});

it('fits a table into one column of a section set in columns', function () {
    // (9026 − 720 of spacing) ÷ 2, because a table in a two-column section has to
    // fit a column rather than run across both.
    $section = section(['colsNum' => 2]);

    expect(array_sum((new TableLayout($section))->columnWidths([10, 10])))->toBe(4153);
});

it('fits a table nested in a cell inside that cell', function () {
    $section = section();
    $table = $section->addTable(['width' => TEXT_COLUMN, 'unit' => 'dxa']);
    $table->addRow();
    $cell = $table->addCell(4000);

    // Less the 160 twips of cell padding this renderer sets.
    expect(array_sum((new TableLayout($cell))->columnWidths([10, 10])))->toBe(3840);
});

it('falls back to a default page for a header or a footer', function () {
    // Neither carries page geometry in PHPWord's API and neither can reach the
    // section, so this is an assumption — asserted so that it is a stated one.
    $section = section();
    $header = $section->addHeader();

    expect(array_sum((new TableLayout($header))->columnWidths([10, 10])))->toBe(TEXT_COLUMN);
});

/**
 * A section, styled before anything is rendered into it: the widths are computed
 * from the page setup that is there at the time, so styling afterwards measures
 * a different page from the one the table was laid out for.
 *
 * @param  array<string, mixed>|null  $style
 */
function section(?array $style = null): Section
{
    $section = (new PhpWord())->addSection();

    if ($style !== null) {
        $section->setStyle($style);
    }

    return $section;
}

it('falls back for a cell that has no width of its own', function () {
    // A cell built by hand rather than by the table above it carries no width, and
    // the fallback is the one a header gets.
    $cell = new Cell();

    expect(array_sum((new TableLayout($cell))->columnWidths([10, 10])))->toBe(TEXT_COLUMN);
});