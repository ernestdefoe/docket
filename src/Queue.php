<?php

namespace ErnestDefoe\Docket;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Group\Group;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string|null $color
 * @property int $position
 * @property Carbon|null $created_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Group> $groups
 */
class Queue extends AbstractModel
{
    public const CACHE_KEY = 'ernestdefoe-docket.queue-access';

    protected $table = 'docket_queues';

    protected $casts = ['position' => 'integer', 'created_at' => 'datetime'];

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'docket_queue_group');
    }

    /**
     * The ids of the queues kept from this user: kept to groups, none of
     * which the user is in. Empty for admins.
     *
     * Reads the queue map from the cache, so it costs no query.
     *
     * @return int[]
     */
    public static function closedTo(User $actor): array
    {
        if ($actor->isAdmin()) {
            return [];
        }

        $groups = array_map('intval', $actor->permissionGroupIds());
        $closed = [];

        foreach (static::accessMap() as $queueId => $queueGroups) {
            if ($queueGroups && ! array_intersect($queueGroups, $groups)) {
                $closed[] = $queueId;
            }
        }

        return $closed;
    }

    /**
     * Every queue's groups, by queue id: [] for a queue open to all.
     *
     * @return array<int, int[]>
     */
    public static function accessMap(): array
    {
        /** @var \Illuminate\Contracts\Cache\Repository $cache */
        $cache = resolve('cache.store');

        return $cache->rememberForever(self::CACHE_KEY, function () {
            $map = static::query()->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => []])->all();

            foreach (static::query()->getConnection()->table('docket_queue_group')->get() as $row) {
                $map[(int) $row->queue_id][] = (int) $row->group_id;
            }

            return $map;
        });
    }

    public static function forgetAccessMap(): void
    {
        resolve('cache.store')->forget(self::CACHE_KEY);
    }

    public function isOpenTo(User $actor): bool
    {
        return ! in_array($this->id, static::closedTo($actor), true);
    }
}
