<?php

namespace ErnestDefoe\Docket\Listener;

use ErnestDefoe\Docket\Docket;
use Flarum\Flags\Event\Cleared;
use Flarum\Flags\Flag;
use Flarum\Post\Event\Deleted;
use Flarum\Post\Event\Hidden;
use Flarum\Post\Event\Restored;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Files every new flag, however it was raised (the flag form, flarum/approval,
 * an anti-spam or AI extension), and logs what happens to reported posts.
 */
class RecordActivity
{
    /**
     * Resolved when an event comes, not when the listener is registered on
     * every request.
     */
    protected function docket(): Docket
    {
        return resolve(Docket::class);
    }

    public function subscribe(Dispatcher $events): void
    {
        // The model event, not flags' Created: that one is only raised by the
        // flag form, and flarum/approval and others save flags directly.
        $events->listen('eloquent.created: '.Flag::class, fn (Flag $flag) => $this->docket()->file($flag));

        $events->listen(Cleared::class, fn (Cleared $event) => $this->docket()->cleared($event->post, $event->actor));

        $events->listen(Hidden::class, fn (Hidden $event) => $this->docket()->postAction($event->post->id, 'post_hidden', $event->actor));
        $events->listen(Restored::class, fn (Restored $event) => $this->docket()->postAction($event->post->id, 'post_restored', $event->actor));
        $events->listen(Deleted::class, fn (Deleted $event) => $this->docket()->postAction($event->post->id, 'post_deleted', $event->actor));
    }
}
