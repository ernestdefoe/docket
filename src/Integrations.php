<?php

namespace ErnestDefoe\Docket;

use Flarum\Extension\ExtensionManager;
use Flarum\Tags\Tag;

/**
 * Which optional extensions Docket works with on this forum, decided once
 * per request (a singleton, DocketServiceProvider), and what it reads from
 * them.
 *
 * Asked at run time, not through Extend\Conditional: what Docket registers
 * for these extensions is already conditional in extend.php, but these
 * answers decide steps inside Docket's own code (the crowd's action, a rule's
 * tags, the tag part of report visibility), and those run in queued jobs and
 * in the request that disables an extension, too.
 */
class Integrations
{
    public readonly bool $approval;

    public readonly bool $tags;

    public function __construct(
        ExtensionManager $extensions
    ) {
        $this->approval = $extensions->isEnabled('flarum-approval');
        $this->tags = $extensions->isEnabled('flarum-tags');
    }

    /**
     * A discussion's tags, through flarum/tags' own model: none without it.
     *
     * @return int[]
     */
    public function tagIds(?int $discussionId): array
    {
        if (! $this->tags || ! $discussionId) {
            return [];
        }

        return Tag::query()->whereHas('discussions', fn ($query) => $query->whereKey($discussionId))->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
