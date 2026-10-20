<?php

namespace ErnestDefoe\Docket\Api;

use ErnestDefoe\Docket\Docket;
use ErnestDefoe\Docket\Queue;
use ErnestDefoe\Docket\Report;
use Flarum\Api\Context as FlarumContext;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context;
use WeakMap;

/**
 * Reports, for the staff who may see them. Every change goes through an
 * update: claim or assign (assignee), file in a queue (queue), and move
 * through open / in progress / resolved / rejected (status), telling the
 * reporters (notifyReporters, outcome) when it closes.
 *
 * @extends AbstractDatabaseResource<Report>
 */
class ReportResource extends AbstractDatabaseResource
{
    /** @var WeakMap<Report, array<string, mixed>>|null */
    protected ?WeakMap $changes = null;

    public function __construct(
        protected TranslatorInterface $translator
    ) {
    }

    /**
     * Resolved on use: every API resource is built on every API request.
     */
    protected function docket(): Docket
    {
        return resolve(Docket::class);
    }

    public function type(): string
    {
        return 'docket-reports';
    }

    public function model(): string
    {
        return Report::class;
    }

    public function scope(Builder $query, Context $context): void
    {
        $query->whereVisibleTo($context->getActor());
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->authenticated()
                ->defaultInclude(['post', 'post.user', 'post.discussion', 'user', 'assignee', 'queue'])
                ->eagerLoad(['post.discussion.state', 'post.user.groups', 'user.groups', 'assignee.groups'])
                ->defaultSort('-updatedAt')
                ->paginate(20, 50),
            Endpoint\Show::make()
                ->authenticated()
                ->defaultInclude(['post', 'post.user', 'post.discussion', 'user', 'assignee', 'queue', 'closedBy', 'entries', 'entries.user', 'events', 'events.user']),
            Endpoint\Update::make()
                ->authenticated()
                ->defaultInclude(['assignee', 'queue', 'closedBy', 'events', 'events.user']),
        ];
    }

    public function fields(): array
    {
        // No `discussion` relationship: notifications include
        // `subject.discussion` for every subject type that has one, and for a
        // report that would be a query per notification. It is post.discussion.
        return [
            Schema\Str::make('status')
                ->writable()
                ->in(Report::STATUSES)
                ->set(fn (Report $report, string $value) => $this->want($report, 'status', $value)),
            Schema\Boolean::make('notifyReporters')
                ->writable()
                ->visible(false)
                ->set(fn (Report $report, bool $value) => $this->want($report, 'notify', $value)),
            Schema\Str::make('outcome')
                ->writable()
                ->nullable()
                ->maxLength(2000)
                ->set(fn (Report $report, ?string $value) => $this->want($report, 'outcome', $value)),
            Schema\Boolean::make('reportersNotified')
                ->property('reporters_notified'),
            Schema\Integer::make('entryCount'),
            Schema\DateTime::make('createdAt'),
            Schema\DateTime::make('updatedAt'),
            Schema\DateTime::make('closedAt'),
            Schema\Boolean::make('canAssignOthers')
                ->get(fn (Report $report, FlarumContext $context) => $context->getActor()->hasPermission('docket.assignOthers')),

            Schema\Relationship\ToOne::make('post')
                ->includable()
                ->nullable(),
            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable()
                ->nullable(),
            Schema\Relationship\ToOne::make('assignee')
                ->type('users')
                ->includable()
                ->nullable()
                ->writable()
                ->set(fn (Report $report, ?User $user) => $this->want($report, 'assignee', $user)),
            Schema\Relationship\ToOne::make('queue')
                ->type('docket-queues')
                ->includable()
                ->nullable()
                ->writable()
                ->set(fn (Report $report, ?Queue $queue) => $this->want($report, 'queue', $queue)),
            Schema\Relationship\ToOne::make('closedBy')
                ->type('users')
                ->includable()
                ->nullable(),
            Schema\Relationship\ToMany::make('entries')
                ->type('docket-entries')
                ->includable(),
            Schema\Relationship\ToMany::make('events')
                ->type('docket-events')
                ->includable(),
        ];
    }

    public function sorts(): array
    {
        return [
            SortColumn::make('createdAt'),
            SortColumn::make('updatedAt'),
        ];
    }

    protected function want(Report $report, string $key, mixed $value): void
    {
        $this->changes ??= new WeakMap();
        $this->changes[$report] = [$key => $value] + ($this->changes[$report] ?? []);
    }

    /**
     * Apply what was asked, in order: the queue, then who holds the report,
     * then its status. Each is checked first, so nothing is half done.
     */
    public function update(object $model, Context $context): object
    {
        $changes = $this->changes[$model] ?? [];
        unset($this->changes[$model]);

        /** @var User $actor */
        $actor = $context->getActor();
        $this->check($model, $changes, $actor);

        $model->getConnection()->transaction(function () use ($model, $changes, $actor) {
            if (array_key_exists('queue', $changes)) {
                $this->docket()->move($model, $changes['queue'], $actor);
            }

            if (array_key_exists('assignee', $changes)) {
                $this->docket()->assign($model, $changes['assignee'], $actor);
            }

            if (isset($changes['status']) && $changes['status'] !== $model->status) {
                $status = $changes['status'];

                if (! in_array($status, Report::ACTIVE, true)) {
                    $this->docket()->close($model, $status, $actor, (bool) ($changes['notify'] ?? false), $changes['outcome'] ?? null);
                } elseif (! $model->isActive()) {
                    $this->docket()->reopen($model, $status, $actor);
                } elseif ($status === Report::OPEN) {
                    $this->docket()->assign($model, null, $actor);
                } else {
                    $this->docket()->assign($model, $actor, $actor);
                }
            }
        });

        return $model->refresh();
    }

    /**
     * @param array<string, mixed> $changes
     */
    protected function check(Report $report, array $changes, User $actor): void
    {
        $status = $changes['status'] ?? $report->status;
        $active = in_array($status, Report::ACTIVE, true);
        $othersMay = $actor->hasPermission('docket.assignOthers');

        // Taking a report from whoever holds it, or giving it to someone else.
        $holder = $report->assignee_id;
        $takesFromOther = $holder && $holder !== $actor->id;

        if (array_key_exists('assignee', $changes)) {
            /** @var User|null $to */
            $to = $changes['assignee'];

            if (! $report->isActive() || ! $active) {
                $this->fail('assignee', 'closed');
            }

            if ($to?->id !== $holder && ((($to && $to->id !== $actor->id) || $takesFromOther) && ! $othersMay)) {
                $this->fail('assignee', 'assign_others');
            }

            if ($to && $to->id !== $actor->id && ! $this->docket()->canSee($to, $report)) {
                $this->fail('assignee', 'cannot_see');
            }
        }

        if (isset($changes['status']) && $changes['status'] !== $report->status && $report->isActive() && $active && $takesFromOther && ! $othersMay && ! array_key_exists('assignee', $changes)) {
            // Moving between open and in progress hands the report to you or
            // to no one, which is taking it from its holder.
            $this->fail('status', 'assign_others');
        }

        if (array_key_exists('queue', $changes) && ! $report->isActive()) {
            $this->fail('queue', 'closed');
        }
    }

    protected function fail(string $field, string $key): never
    {
        throw new ValidationException([$field => $this->translator->trans("ernestdefoe-docket.api.$key")]);
    }
}
