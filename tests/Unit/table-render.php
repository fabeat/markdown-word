<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Styles;
use PhpOffice\PhpWord\Element\Table;

const SIMPLE = "| Name | Age |\n"
        . "| :---- | ---: |\n"
        . "| Ann   |    30 |\n"
        . "| Bob   |    40 |";

it('table becomes a single table element', function () {
    $elements = renderElements(SIMPLE . "\n");

    expect($elements)->toHaveCount(1);
    expect($elements[0])->toBeInstanceOf(Table::class);
});

it('rows and cells match the source', function () {
    $table = renderElements(SIMPLE . "\n")[0];

    expect($table->getRows())->toHaveCount(3);
    expect($table->getRows()[0]->getCells())->toHaveCount(2);
    expect($table->getRows()[2]->getCells())->toHaveCount(2);
});

it('cell content is preserved', function () {
    expect(renderText(SIMPLE . "\n"))->toBe("Name\tAge\nAnn\t30\nBob\t40");
});

it('header row is bold by default', function () {
    $runs = headerRuns(0);

    expect($runs[0]['font']['bold'] ?? false)->toBeTrue();
});

it('body cells are not bold', function () {
    $runs = cellRuns(1, 0);

    expect($runs[0]['font'] ?? null)->toBeNull();
});

it('header boldness can be turned off', function () {
    $config = Configuration::create()->withOptions(['tableHeaderBold' => false]);

    expect(headerRuns(0, $config)[0]['font'] ?? null)->toBeNull();
});

it('column alignment comes from the delimiter row', function () {
    expect(cellAlignment(1, 0))->toBe('left');
    expect(cellAlignment(1, 1))->toBe('right');
});

it('centred columns are recognised', function () {
    $table = renderElements("| a | b |\n| :---: | ---: |\n| 1 | 2 |\n")[0];
    $paragraph = $table->getRows()[1]->getCells()[0]->getElements()[0];

    expect($paragraph->getParagraphStyle()->getAlignment())->toBe('center');
});

it('inline formatting works inside cells', function () {
    $table = renderElements("| a | b |\n| --- | --- |\n| **bold** | `code` |\n")[0];
    $runs = renderRuns($table->getRows()[1]->getCells()[0]->getElements()[0]);

    expect($runs[0]['font']['bold'] ?? false)->toBeTrue();
});

it('table with a single body row still renders', function () {
    $table = renderElements("| a | b |\n| --- | --- |\n| 1 | 2 |\n")[0];

    expect($table->getRows())->toHaveCount(2);
});

it('empty cells render', function () {
    $table = renderElements("| a | b |\n| --- | --- |\n| 1 |   |\n")[0];

    expect($table->getRows()[1]->getCells())->toHaveCount(2);
});

it('table borders are drawn by default', function () {
    $table = renderElements(SIMPLE . "\n")[0];

    expect(array_values(array_unique($table->getStyle()->getBorderColor())))->toBe(['000000']);
});

it('table borders can be disabled', function () {
    $config = Configuration::create()->withOptions(['tableBorders' => false]);
    $table = renderElements(SIMPLE . "\n", $config)[0];

    expect(array_values(array_unique($table->getStyle()->getBorderColor())))->toBe([null]);
});

it('table can use a named word table style', function () {
    $config = Configuration::create()->withStyles([
        Styles::TABLE => 'Grid Table 4 - Accent 1',
    ]);

    $table = renderElements(SIMPLE . "\n", $config)[0];

    // A named style is kept as a name so the template's definition is used.
    expect($table->getStyle())->toBe('Grid Table 4 - Accent 1');
});

it('cell style applies to every cell', function () {
    $config = Configuration::create()->withStyles([
        Styles::TABLE_CELL => ['alignment' => ['horizontal' => 'center']],
    ]);

    $table = renderElements("| a |\n| --- |\n| 1 |\n", $config)[0];
    $paragraph = $table->getRows()[1]->getCells()[0]->getElements()[0];

    expect($paragraph->getParagraphStyle()->getAlignment())->toBe('center');
});

    /**
     * @return list<array{text: string, font: array<string, mixed>|string|null}>
     */
function headerRuns(int $column, ?Configuration $config = null) : array
{
        return cellRuns(0, $column, $config);
    }

    /**
     * @return list<array{text: string, font: array<string, mixed>|string|null}>
     */
function cellRuns(int $row, int $column, ?Configuration $config = null) : array
{
        $table = renderElements(SIMPLE . "\n", $config)[0];
        $paragraph = $table->getRows()[$row]->getCells()[$column]->getElements()[0];

        return renderRuns($paragraph);
    }

function cellAlignment(int $row, int $column) : ?string
{
        $table = renderElements(SIMPLE . "\n")[0];
        $paragraph = $table->getRows()[$row]->getCells()[$column]->getElements()[0];

        return $paragraph->getParagraphStyle()?->getAlignment();
    }
