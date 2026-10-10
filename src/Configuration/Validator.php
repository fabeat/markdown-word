<?php

declare(strict_types=1);

namespace MarkdownWord\Configuration;

use MarkdownWord\Exception\InvalidConfiguration;
use MarkdownWord\Exception\InvalidConfigurationValue;
use MarkdownWord\Exception\UnknownConfigurationKey;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Cell;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\Style\Row;
use PhpOffice\PhpWord\Style\Table;

/**
 * Reports the configuration that cannot be used: the keys that name nothing, and the
 * values that are not what they are used as.
 *
 * Four places take a key and quietly discard one they do not recognise: an unknown
 * option, an unknown style slot, an unknown font property inside a style, and an
 * unknown paragraph property inside one. Each leaves a document that renders fine and
 * is configured as though the key had never been written, which is the worst of the
 * available outcomes — nothing looks broken and the setting is simply missing.
 *
 * A value is the same class of problem and was the quieter half. `maxHeadingLevel:
 * deep` is cast to 0, clamped to 1, and the document comes out with every heading in
 * it rendered as body text; `space: {before: "lots"}` loses the `before` and keeps the
 * `after`; `color: "#8B0000"` reaches `w:color` with a `#` in it and Word ignores it.
 * None of those looks wrong, which is the whole reason they are reported.
 *
 * So every key and every value is checked, and every problem is reported rather than
 * the first, so a file with three mistakes takes one run to fix rather than three.
 *
 * ```php
 * Validator::assertValid(['options' => ['maxHeadingLevel' => 'deep']]);
 * ```
 *
 * This is raised where it is called rather than from `fromArray()` or `withAll()`.
 * Those are the documented behaviour of a value object and there are callers relying
 * on them: a config file may carry keys for something else, and a key dropped there
 * has been dropped for a decade. Frontmatter is new, is what a language model writes,
 * and is validated on the way in.
 *
 * A property nobody could plausibly get wrong is not checked: `name` is any font on
 * the machine and `orderedListFormat` is any of the OOXML numbering formats, and an
 * allow-list for either would be a list nobody could keep up to date.
 */
final class Validator
{
    /** The keys a configuration array may hold at its top level. */
    private const SECTIONS = ['options', 'styles'];

    /**
     * Every problem with a configuration array, in the order the keys were written.
     *
     * @param array<string, mixed> $config
     * @param callable(string): ?int|null $locate The file line a path sits on, for
     *        `options/images` or `styles/heading.1/color`. Null for a configuration
     *        built in code, where there is no file to point into.
     *
     * @return list<string>
     */
    public static function problems(array $config, ?callable $locate = null): array
    {
        return array_column(self::collected($config, $locate), 'message');
    }

    /**
     * @param array<string, mixed> $config
     * @param callable(string): ?int|null $locate
     * @param list<string> $alsoAboutValues Problems already gathered elsewhere and known to
     *        be about values rather than keys — a `---` block that is not a mapping at
     *        all, which {@see \MarkdownWord\Document\Frontmatter} knows about and this
     *        does not. They are reported with the rest, and they decide the type of the
     *        exception along with anything found here.
     *
     * @throws InvalidConfiguration when anything in the array cannot be used.
     */
    public static function assertValid(array $config, ?callable $locate = null, array $alsoAboutValues = []): void
    {
        $problems = [
            ...array_map(
                static fn (string $message): array => ['message' => $message, 'about' => 'value'],
                $alsoAboutValues,
            ),
            ...self::collected($config, $locate),
        ];

        if ($problems === []) {
            return;
        }

        $aboutValues = [];

        foreach ($problems as $problem) {
            $aboutValues[] = $problem['about'] === 'value';
        }

        $message = implode("\n", array_column($problems, 'message'));

        // `UnknownConfigurationKey` is what a caller has been catching since keys
        // were checked, so a batch that is only about keys keeps it. Both extend
        // `InvalidConfiguration`, which is what a caller who does not care should catch.
        throw \in_array(true, $aboutValues, true)
            ? new InvalidConfigurationValue($message)
            : new UnknownConfigurationKey($message);
    }

    /**
     * One problem, written so that it can be acted on without looking anything up.
     *
     * The near-miss is the useful part. `blockquote` for `blockQuote` is a mistake
     * anyone makes once and cannot see from the error alone, so a key close enough to
     * be a plausible typo of a real one is named as a suggestion rather than left among
     * twenty alternatives.
     *
     * The key is quoted and the rest is built around it, because the three kinds read
     * differently — `Unknown option "x"`, `Unknown style "x"`, `Unknown "x" property
     * of the "y" style` — and a single interpolated noun would have to give up on
     * one of them.
     *
     * @param string       $before  what to say before the quoted key
     * @param string       $after   what to say after it
     * @param list<string> $known   every key that would have been accepted
     * @param string       $plural  how the alternatives are introduced
     */
    public static function describe(
        string $key,
        string $before,
        string $after,
        array $known,
        string $plural,
    ): string {
        sort($known);

        $suggestion = self::closest($key, $known);

        return \sprintf(
            'Unknown %s"%s"%s.%s Known %s: %s.',
            $before,
            $key,
            $after,
            $suggestion === null ? '' : \sprintf(' Did you mean "%s"?', $suggestion),
            $plural,
            implode(', ', $known),
        );
    }

    /**
     * One value problem, in the shape {@see self::describe()} gives a key one.
     *
     * The sentence is split after the value so that the second half can be whatever
     * the mistake needs — "It is one of: embed, placeholder, skip." for a mode that
     * does not exist, and "`lots` is not a number, it goes under `before:`" for a
     * space with a word in it — rather than one clause bent to cover both.
     *
     * @param string $where what to say the value is, named as it reads in the document
     * @param string $given the value as it was written
     * @param string $rest  what a value that would have worked looks like
     */
    public static function describeValue(string $where, string $given, string $rest): string
    {
        return \sprintf('%s is %s. %s', $where, $given, $rest);
    }

    /**
     * The value as the reader wrote it, for a message that has to show it back.
     *
     * A scalar is quoted so a value of `no` cannot be read as the sentence, and a
     * block is described by its keys rather than reproduced: the point is to let
     * someone recognise what they wrote, not to echo it.
     */
    public static function show(mixed $value): string
    {
        if ($value === null) {
            return 'nothing';
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (\is_array($value)) {
            if ($value === []) {
                return 'empty';
            }

            return \array_is_list($value)
                ? 'a list: [' . implode(', ', array_map(self::show(...), $value)) . ']'
                : 'a mapping of ' . implode(', ', array_map(strval(...), array_keys($value)));
        }

        return \is_scalar($value) ? '"' . (string) $value . '"' : get_debug_type($value);
    }

    /**
     * A problem with the line it is on in front of it, when there is one.
     *
     * The line is what turns "Unknown option" into something to go and fix: a block
     * is a list of keys with nothing to say which one is wrong, and there is rarely
     * more than one document in a run.
     */
    public static function locate(string $message, ?int $line): string
    {
        return $line === null ? $message : \sprintf('Line %d: %s', $line, $message);
    }

    /**
     * Every problem, with enough to tell which exception type describes the batch.
     *
     * @param array<string, mixed> $config
     * @param callable(string): ?int|null $locate
     *
     * @return list<array{message: string, about: string}>
     */
    private static function collected(array $config, ?callable $locate): array
    {
        $problems = [];

        foreach ($config as $section => $value) {
            if (!\in_array($section, self::SECTIONS, true) || !\is_array($value)) {
                continue;
            }

            $options = $section === 'options';
            $known = $options
                ? \array_keys((new Options())->toArray())
                : \array_keys(Styles::defaults());

            foreach ($value as $key => $given) {
                $key = (string) $key;

                if (\in_array($key, $known, true)) {
                    $problems = [...$problems, ...self::optionValue($key, $given, $locate)];

                    continue;
                }

                $problems[] = [
                    'message' => self::locate(self::describe(
                        $key,
                        $options ? 'option ' : 'style ',
                        '',
                        $known,
                        $options ? 'options' : 'styles',
                    ), self::line($locate, $section . '/' . $key)),
                    'about' => 'key',
                ];
            }

            if (!$options) {
                $problems = [...$problems, ...self::styleValues($value, $locate)];
            }
        }

        return $problems;
    }

    /**
     * @param array<array-key, mixed> $styles
     * @param callable(string): ?int|null $locate
     *
     * @return list<array{message: string, about: string}>
     */
    private static function styleValues(array $styles, ?callable $locate): array
    {
        $problems = [];

        foreach ($styles as $slot => $definition) {
            foreach (self::styleValue((string) $slot, $definition, $locate) ?? [] as $problem) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    /**
     * The problems with one slot's definition, or null when there are none.
     *
     * A slot holds three things: a Word style name, a mapping of properties, or
     * nothing. A list is the fourth, and it is the shape a hand-written block falls
     * into by accident — `heading.1:` followed by `- size` — where every entry would
     * otherwise be reported as a property named "0".
     *
     * @param callable(string): ?int|null $locate
     * @return list<array{message: string, about: string}>
     */
    private static function styleValue(string $slot, mixed $definition, ?callable $locate): array
    {
        if ($definition === null || \is_string($definition)) {
            return [];
        }

        if (!\is_array($definition) || ($definition !== [] && \array_is_list($definition))) {
            return [[
                'message' => self::locate(\sprintf(
                    'The "%s" style is %s, which is neither the name of a style nor a set of properties. '
                    . 'A slot holds either a Word style name — Quote, Heading1 — or the properties '
                    . 'themselves, one per line beneath it.',
                    $slot,
                    self::show($definition),
                ), self::line($locate, 'styles/' . $slot)),
                'about' => 'value',
            ]];
        }

        $properties = self::propertiesOf($slot);
        $problems = [];

        foreach ($definition as $property => $given) {
            $property = (string) $property;
            $path = 'styles/' . $slot . '/' . $property;

            if (!\in_array($property, $properties, true)) {
                $problems[] = [
                    'message' => self::locate(self::describe(
                        $property,
                        '',
                        \sprintf(' property of the "%s" style', $slot),
                        $properties,
                        'style properties',
                    ), self::line($locate, $path)),
                    'about' => 'key',
                ];

                continue;
            }

            $rest = self::propertyValue($slot, $property, $given);

            if ($rest === null) {
                continue;
            }

            $problems[] = [
                'message' => self::valueMessage(
                    \sprintf('The "%s" property of the "%s" style', $property, $slot),
                    $given,
                    $rest,
                    self::line($locate, $path),
                ),
                'about' => 'value',
            ];
        }

        return $problems;
    }

    /**
     * The problems with one option's value, or none.
     *
     * Both ends of both ranges are checked, because the cast and the clamp that used
     * to absorb them turn a typo into a different document rather than into a smaller
     * number: `deep` becomes 0, and 0 is clamped to 1, which renders every heading in
     * the document as body text.
     *
     * @param callable(string): ?int|null $locate
     * @return list<array{message: string, about: string}>
     */
    private static function optionValue(string $key, mixed $given, ?callable $locate): array
    {
        $rest = match ($key) {
            'softBreak' => self::oneOf($given, [Options::SOFT_BREAK_SPACE, Options::SOFT_BREAK_LINE, Options::SOFT_BREAK_PARAGRAPH]),
            'hardBreak' => self::oneOf($given, [Options::BREAK_REMOVE, Options::BREAK_LINE, Options::BREAK_PARAGRAPH]),
            'html' => self::oneOf($given, [Options::HTML_STRIP, Options::HTML_PRESERVE, Options::HTML_DROP]),
            'images' => self::oneOf($given, [Options::IMAGE_EMBED, Options::IMAGE_PLACEHOLDER, Options::IMAGE_SKIP]),
            'orderedListSuffix' => self::oneOf($given, ['tab', 'space', 'nothing']),
            'thematicBreak' => self::oneOf($given, ['border', 'text']),
            'maxHeadingLevel' => self::between($given, 1, 6),
            'tableWidth' => self::between($given, 0, 5000),
            'imageMaxWidth' => self::number($given, 0.0),
            'tableBorders', 'tableHeaderBold', 'codeBlockShading', 'deferredHyperlinks' => self::boolean($given),
            'imageBasePath' => \is_string($given) ? null : 'It is a directory, written as a string or left out.',
            default => null,
        };

        if ($rest === null) {
            return [];
        }

        return [[
            'message' => self::valueMessage(
                \sprintf('The "%s" option', $key),
                $given,
                $rest,
                self::line($locate, 'options/' . $key),
            ),
            'about' => 'value',
        ]];
    }

    /**
     * The property names one slot's definition may use.
     *
     * A style slot is not one kind of thing. A heading and a body paragraph are split
     * between a `Font` and a `Paragraph`, which is what {@see Styles::FONT_KEYS} and
     * {@see Styles::PARAGRAPH_KEYS} are the names of; `table` is a `Table`,
     * `tableCell` a `Cell` and `tableHeaderRow` a `Row`, and each has names the other
     * two do not have. `shading` under a header row is the sharpest of them: it is in
     * the list a heading uses, and the row is handed a `RowStyle` with no
     * `setShading()` on it at all — written, accepted, dropped.
     *
     * The three sets are read off the classes rather than copied out, so a setter
     * PHPWord gains is one this accepts.
     *
     * @return list<string>
     */
    private static function propertiesOf(string $slot): array
    {
        return match ($slot) {
            Styles::TABLE => self::settersOf(Table::class),
            Styles::TABLE_HEADER_ROW => self::settersOf(Row::class),
            // `alignment` is not a cell property and is not a font one either: the
            // renderer reads it off the cell style and puts it on the paragraph in
            // the cell, which is the only way a Word cell can be aligned.
            Styles::TABLE_CELL => [...Styles::FONT_KEYS, 'alignment', ...self::settersOf(Cell::class)],
            default => [...Styles::FONT_KEYS, ...Styles::PARAGRAPH_KEYS],
        };
    }

    /**
     * Every property `setStyleByArray()` on a style would dispatch to.
     *
     * Only the ones the class declares. What it inherits is `AbstractElement`'s —
     * `setPhpWord`, `setRelationId`, `setChangeInfo` — which are how the writer
     * attaches the style to the document, not anything a style definition may say.
     *
     * @param class-string $class
     * @return list<string>
     */
    private static function settersOf(string $class): array
    {
        $names = [];

        foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            if (str_starts_with($method->getName(), 'set')) {
                $names[] = lcfirst(substr($method->getName(), 3));
            }
        }

        return $names;
    }

    /**
     * The rest of the sentence for one style property whose value is unusable, or null
     * when nothing is.
     *
     * `$slot` is only there because a table property is not a font or a paragraph
     * one, and the answer for one of those is a different sentence.
     */
    private static function propertyValue(string $slot, string $property, mixed $given): ?string
    {
        if ($slot === Styles::TABLE) {
            return null;
        }

        return match ($property) {
            'name' => \is_string($given) ? null : 'It is a font name, written as a string.',
            'styleName' => \is_string($given) ? null : 'It is the name of a Word style, written as a string.',
            'size' => self::number($given, 0.001),
            'lineHeight' => self::number($given, 0.001),
            'color' => self::colour($given),
            'bold', 'italic', 'strikethrough', 'keepNext' => self::boolean($given),
            'underline' => \is_bool($given) ? null : self::oneOf($given, self::wordValues(Font::class, 'UNDERLINE_')),
            'alignment' => self::oneOf($given, self::wordValues(Jc::class)),
            'space' => self::mapping($given, 'before: and after:'),
            'indentation' => self::mapping($given, 'left:, right:, firstLine: or hanging:'),
            // Colours rather than numbers: `fill` is a hex triplet, and `space` and
            // `indentation` are the two that are measured.
            'shading' => self::colourMap($given, 'fill:'),
            default => null,
        };
    }

    private static function valueMessage(string $where, mixed $given, string $rest, ?int $line): string
    {
        return self::locate(
            InvalidConfigurationValue::for($where, self::show($given), $rest)->getMessage(),
            $line,
        );
    }

    private static function line(?callable $locate, string $path): ?int
    {
        return $locate === null ? null : $locate($path);
    }

    private static function oneOf(mixed $given, array $allowed): ?string
    {
        if (\is_string($given) && \in_array($given, $allowed, true)) {
            return null;
        }

        sort($allowed);

        return 'It is one of: ' . implode(', ', $allowed) . '.';
    }

    private static function between(mixed $given, int $lowest, int $highest): ?string
    {
        if (\is_int($given) && $given >= $lowest && $given <= $highest) {
            return null;
        }

        return \sprintf('It is a whole number between %d and %d.', $lowest, $highest);
    }

    /**
     * A number, at or above `$lowest`.
     *
     * A numeric string is accepted because that is what an unquoted scalar is: a
     * colour written `123456` and a size written `9` are both numbers to whoever wrote
     * them, and refusing them would be refusing the shorthand YAML already allows.
     */
    private static function number(mixed $given, float $lowest): ?string
    {
        if ($lowest > 0.0 && $given === 0) {
            return 'It is a size in points, and 0 makes the text invisible in Word.';
        }

        if (\is_int($given) || \is_float($given) || (\is_string($given) && \is_numeric($given))) {
            return (float) $given >= $lowest ? null : \sprintf('It is a number of at least %s.', $lowest);
        }

        return 'It is a number.';
    }

    /**
     * A colour is six hexadecimal digits and nothing else.
     *
     * `#8B0000` is the CSS spelling and reaches `w:color` with the `#` still in it,
     * which Word ignores; `darkred` is not a colour there either. A YAML integer is
     * tested as its digits, so `123456` passes and `0xFF0000` — which YAML has
     * already turned into `16711680` — does not.
     */
    private static function colour(mixed $given): ?string
    {
        if (\is_string($given) || \is_int($given)) {
            if (preg_match('/^[0-9A-Fa-f]{6}$/', (string) $given) === 1) {
                return null;
            }
        }

        return 'It is six hexadecimal digits, as in 8B0000, with no leading # and no colour name.';
    }

    /**
     * A boolean, or one of the spellings YAML and people write for one.
     *
     * `(bool) "no"` is `true`, so a quoted `false` in a block is not a small mistake:
     * it turns an option off and writes the opposite into the document.
     */
    private static function boolean(mixed $given): ?string
    {
        if (\is_bool($given)) {
            return null;
        }

        if (\is_string($given) && \in_array(\strtolower($given), ['true', 'false', 'yes', 'no', 'on', 'off'], true)) {
            return null;
        }

        return 'It is true or false.';
    }

    /**
     * A mapping of numbers, as `space` and `indentation` are read.
     *
     * A scalar under one of them is dropped whole, so `space: 480` leaves the paragraph
     * with no space at all and the document looks as though nothing had been asked for.
     * A word inside one loses its own key only, which is a quieter failure still.
     */
    private static function mapping(mixed $given, string $written): ?string
    {
        $expected = \sprintf('It is a mapping of numbers, with %s beneath it.', $written);

        if (!\is_array($given) || \array_is_list($given)) {
            return $expected;
        }

        foreach ($given as $key => $value) {
            if (!\is_int($value) && !\is_float($value) && !(\is_string($value) && \is_numeric($value))) {
                return \sprintf('%s is not a number, and it goes under %s.', self::show($value), $written);
            }
        }

        return null;
    }

    /**
 * A mapping whose values are colours, as `shading` is read.
 *
 * A scalar under it is dropped whole, so `shading: F2F2F2` leaves the paragraph with
 * no background and the document looks as though nothing had been asked for.
 */
    private static function colourMap(mixed $given, string $written): ?string
    {
        return \is_array($given) && !\array_is_list($given)
            ? null
            : \sprintf('It is a mapping, with %s beneath it.', $written);
    }

    /**
     * Every string constant PHPWord holds for a property, sorted.
     *
     * Read off the class rather than copied out, so the list in the message cannot
     * fall behind the one in the writer: `w:jc` and `w:u` are closed sets, and
     * anything outside one is written into the document and ignored by Word.
     *
     * @param class-string $class
     * @return list<string>
     */
    private static function wordValues(string $class, string $prefix = ''): array
    {
        $values = [];

        foreach ((new \ReflectionClass($class))->getConstants() as $name => $value) {
            if (\is_string($value) && ($prefix === '' || str_starts_with((string) $name, $prefix))) {
                $values[] = $value;
            }
        }

        sort($values);

        return $values;
    }

    /**
     * The known key nearest to the one given, if it is close enough to be a typo.
     *
     * The threshold is two edits over a case-insensitive comparison, so `blockquote`
     * finds `blockQuote` and `maxheadinglevel` finds `maxHeadingLevel`, while a key
     * that is merely different stays unprompted — a suggestion that is wrong is worse
     * than none, because it sends the reader to the wrong place to look.
     *
     * @param list<string> $known
     */
    private static function closest(string $key, array $known): ?string
    {
        $needle = \strtolower($key);
        $best = null;
        $bestDistance = \PHP_INT_MAX;

        foreach ($known as $candidate) {
            $distance = self::distance($needle, \strtolower($candidate));

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }

        return $best !== null && $bestDistance <= \max(1, \intdiv(\strlen($needle), 3)) ? $best : null;
    }

    /**
     * Levenshtein distance, computed row by row so the two words in memory stay short
     * rather than one matrix the length of both squared.
     */
    private static function distance(string $a, string $b): int
    {
        $previous = \range(0, \strlen($b));

        for ($i = 1; $i <= \strlen($a); $i++) {
            $current = [$i];

            for ($j = 1; $j <= \strlen($b); $j++) {
                $current[$j] = min(
                    $previous[$j] + 1,
                    $current[$j - 1] + 1,
                    $previous[$j - 1] + ($a[$i - 1] === $b[$j - 1] ? 0 : 1),
                );
            }

            $previous = $current;
        }

        return $previous[\strlen($b)];
    }
}