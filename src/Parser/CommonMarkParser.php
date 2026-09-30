<?php

declare(strict_types=1);

namespace MarkdownWord\Parser;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\DefaultAttributes\DefaultAttributesExtension;
use League\CommonMark\Extension\DescriptionList\DescriptionListExtension;
use League\CommonMark\Extension\DisallowedRawHtml\DisallowedRawHtmlExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\Footnote\FootnoteExtension;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\Highlight\HighlightExtension;
use League\CommonMark\Extension\Mention\MentionExtension;
use League\CommonMark\Extension\NormalizeHeadings\NormalizeHeadingsExtension;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Parser\MarkdownParser;

/**
 * The default parser: full CommonMark plus the GitHub-Flavored Markdown
 * extensions (tables, strikethrough, task lists, autolinks) and a few extras
 * that are common in READMEs.
 *
 * The important part is what this returns: the *abstract syntax tree*. Nothing is
 * lost on the way through HTML, so the renderer sees exactly what the Markdown
 * author wrote — emphasis nesting, hard breaks, entity references and link
 * reference definitions included.
 */
final class CommonMarkParser implements MarkdownParserInterface
{
    /**
     * The extension sets, in terms of what is added *on top of* CommonMark.
     *
     * @var array<string, list<class-string>>
     */
    public const FLAVOURS = [
        'commonmark' => [],
        'gfm' => [GithubFlavoredMarkdownExtension::class],
        'extended' => [
            GithubFlavoredMarkdownExtension::class,
            FootnoteExtension::class,
            DescriptionListExtension::class,
        ],
    ];

    /**
     * @var list<class-string>
     */
    private array $extensions;

    /**
     * @param list<class-string>|null $extensions Extensions to add on top of CommonMark.
     *        `null` selects the default, GFM. An empty list means CommonMark only,
     *        which is why the two cannot be the same value.
     * @param array<string, mixed> $config        CommonMark environment configuration.
     */
    public function __construct(?array $extensions = null, private readonly array $config = [])
    {
        $this->extensions = $extensions === null ? self::FLAVOURS['gfm'] : array_values($extensions);
    }

    /**
     * A parser restricted to the CommonMark specification, without any GFM extras.
     */
    public static function commonMarkOnly(): self
    {
        return new self(self::FLAVOURS['commonmark']);
    }

    /**
     * A parser with the extras that turn up in READMEs on top of GFM: footnotes
     * and description lists.
     */
    public static function extended(): self
    {
        return new self(self::FLAVOURS['extended']);
    }

    /**
     * A parser with every extension the installed `league/commonmark` release
     * ships with that can be enabled without extra configuration.
     *
     * Four are deliberately left out, each for a concrete reason:
     *
     *  - `SmartPunctExtension` and `InlinesOnlyExtension` register their own `*`
     *    and `_` delimiter processors, which collide with CommonMark's emphasis
     *    rules and make the environment refuse to build;
     *  - `EmbedExtension` requires an `embed.adapter` object; and
     *  - `TableOfContentsExtension` requires its own configuration and yields a
     *    placeholder that means little in a Word document.
     *
     * They remain available by constructing the parser with an explicit list.
     */
    public static function withAllExtensions(): self
    {
        return new self([
            ...self::FLAVOURS['gfm'],
            AttributesExtension::class,
            AutolinkExtension::class,
            DefaultAttributesExtension::class,
            DescriptionListExtension::class,
            DisallowedRawHtmlExtension::class,
            ExternalLinkExtension::class,
            FootnoteExtension::class,
            FrontMatterExtension::class,
            HeadingPermalinkExtension::class,
            HighlightExtension::class,
            MentionExtension::class,
            NormalizeHeadingsExtension::class,
        ]);
    }

    public function parse(string $markdown): Document
    {
        $environment = new Environment($this->config);

        $environment->addExtension(new CommonMarkCoreExtension());

        foreach ($this->extensions as $extension) {
            $environment->addExtension(new $extension());
        }

        return (new MarkdownParser($environment))->parse($markdown);
    }
}
