<?php

namespace ErnestDefoe\Docket\Api;

use Carbon\Carbon;
use ErnestDefoe\Docket\Queue;
use Flarum\Api\Context as FlarumContext;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context;

/**
 * Report queues. Staff see the ones open to them; only admins change them,
 * or see which groups they are kept to.
 *
 * @extends AbstractDatabaseResource<Queue>
 */
class QueueResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'docket-queues';
    }

    public function model(): string
    {
        return Queue::class;
    }

    public function scope(Builder $query, Context $context): void
    {
        $actor = $context->getActor();

        if (! $actor->hasPermissionLike('discussion.viewFlags') && ! $actor->isAdmin()) {
            $query->whereRaw('1 = 0');
        } elseif ($closed = Queue::closedTo($actor)) {
            $query->whereNotIn('docket_queues.id', $closed);
        }
    }

    public function newModel(Context $context): object
    {
        $queue = new Queue();

        if ($context->creating(self::class)) {
            $queue->created_at = Carbon::now();
            $queue->position = (int) Queue::query()->max('position') + 1;
        }

        return $queue;
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->authenticated()
                ->defaultSort('position'),
            Endpoint\Create::make()
                ->admin(),
            Endpoint\Update::make()
                ->admin(),
            Endpoint\Delete::make()
                ->admin(),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('name')
                ->writable()
                ->requiredOnCreate()
                ->minLength(1)
                ->maxLength(100),
            Schema\Str::make('description')
                ->writable()
                ->nullable()
                ->maxLength(255),
            Schema\Str::make('color')
                ->writable()
                ->nullable()
                ->regex('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/'),
            Schema\Integer::make('position'),

            Schema\Relationship\ToMany::make('groups')
                ->writable(fn (Queue $queue, FlarumContext $context) => $context->getActor()->isAdmin())
                ->visible(fn (Queue $queue, FlarumContext $context) => $context->getActor()->isAdmin())
                ->includable(),
        ];
    }

    public function sorts(): array
    {
        return [
            \Flarum\Api\Sort\SortColumn::make('position'),
        ];
    }

    public function saved(object $model, Context $context): ?object
    {
        Queue::forgetAccessMap();

        return parent::saved($model, $context);
    }

    public function deleted(object $model, Context $context): void
    {
        Queue::forgetAccessMap();

        parent::deleted($model, $context);
    }
}
