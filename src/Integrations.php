<?php

namespace ErnestDefoe\Docket;

use Flarum\Extension\ExtensionManager;
use Illuminate\Database\ConnectionInterface;

/**
 * Which optional extensions Docket works with on this forum, decided once
 * per request (a singleton, DocketServiceProvider), and what it reads from
 * them.
 */
class Integrations
{
    public readonly bool $approval;

    public readonly bool $tags;

    public function __construct(
        protected ConnectionInterface $db,
        ExtensionManager $extensions
    ) {
        $this->approval = $extensions->isEnabled('flarum-approval');
        $this->tags = $extensions->isEnabled('flarum-tags');
    }

    /**
     * A discussion's tags: none without flarum/tags.
     *
     * @return int[]
     */
    public function tagIds(?int $discussionId): array
    {
        if (! $this->tags || ! $discussionId) {
            return [];
        }

        return $this->db->table('discussion_tag')->where('discussion_id', $discussionId)->pluck('tag_id')->map(fn ($id) => (int) $id)->all();
    }
}
