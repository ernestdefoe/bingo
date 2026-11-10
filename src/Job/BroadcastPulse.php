<?php

namespace ErnestDefoe\Bingo\Job;

use Flarum\Queue\AbstractJob;
use Illuminate\Contracts\Container\Container;

/**
 * Pushes a game's new revision to open cards through flarum/realtime. A
 * job, so a slow or dead realtime daemon delays nobody's call or ruling
 * (realtime needs a queue worker for its own broadcasts anyway). Only the
 * id and the number go out: what changed is fetched with each viewer's
 * own permissions.
 */
class BroadcastPulse extends AbstractJob
{
    public function __construct(public int $gameId, public int $revision)
    {
    }

    public function handle(Container $container): void
    {
        if (! class_exists(\Pusher\Pusher::class) || ! $container->bound(\Pusher\Pusher::class)) {
            return;
        }

        try {
            $container->make(\Pusher\Pusher::class)->trigger('public', 'bingo', ['id' => $this->gameId, 'rev' => $this->revision]);
        } catch (\Throwable) {
            // Realtime is down: the poll covers it.
        }
    }
}
