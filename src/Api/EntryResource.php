<?php

namespace ErnestDefoe\Docket\Api;

use ErnestDefoe\Docket\Entry;
use ErnestDefoe\Docket\Report;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Tobyz\JsonApiServer\Context;

/**
 * The flags in a report, kept after the flags are gone.
 *
 * Listed, they are "My reports": only your own, with the post (when you can
 * still see it) and how the report went, worded for a reporter: whether it
 * was resolved or rejected only when staff chose to tell you.
 *
 * @extends AbstractDatabaseResource<Entry>
 */
class EntryResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'docket-entries';
    }

    public function model(): string
    {
        return Entry::class;
    }

    public function scope(Builder $query, Context $context): void
    {
        $query->whereVisibleTo($context->getActor());

        if ($context->listing(self::class)) {
            $query->where('docket_entries.user_id', $context->getActor()->id);
        }
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->authenticated()
                ->defaultInclude(['post', 'post.discussion'])
                ->eagerLoad(['report', 'post.discussion.state'])
                ->defaultSort('-createdAt')
                ->paginate(20, 50),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('type'),
            Schema\Str::make('reason')->nullable(),
            Schema\Str::make('reasonDetail')->nullable(),
            Schema\DateTime::make('createdAt'),

            // How it went, as the reporter may know it.
            Schema\Str::make('reportStatus')
                ->get(fn (Entry $entry) => $this->withReport($entry, function (Report $report) {
                    if ($report->isActive()) {
                        return $report->status;
                    }

                    return $report->reporters_notified ? $report->status : 'closed';
                })),
            Schema\Str::make('outcome')
                ->nullable()
                ->get(fn (Entry $entry) => $this->withReport($entry, fn (Report $report) => $report->reporters_notified ? $report->outcome : null)),

            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable()
                ->nullable(),
            Schema\Relationship\ToOne::make('post')
                ->includable()
                ->nullable(),
        ];
    }

    /**
     * Entries serialized without their report loaded (the subjects of
     * notifications) wait here, and the first of them to be read loads the
     * reports of all of them at once: a getter that returns a closure is
     * resolved after every other getter.
     *
     * @var Entry[]
     */
    protected array $waiting = [];

    protected function withReport(Entry $entry, callable $value): mixed
    {
        if ($entry->relationLoaded('report')) {
            return $value($entry->report);
        }

        $this->waiting[] = $entry;

        return function () use ($entry, $value) {
            if ($this->waiting) {
                (new Collection($this->waiting))->load('report');
                $this->waiting = [];
            }

            return $value($entry->report);
        };
    }

    public function sorts(): array
    {
        return [
            SortColumn::make('createdAt'),
        ];
    }
}
