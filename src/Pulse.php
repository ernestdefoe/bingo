<?php

namespace ErnestDefoe\Bingo;

use ErnestDefoe\Bingo\Job\BroadcastPulse;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Queue;

/**
 * The one number every open card watches. It goes up when something all
 * viewers should see changes (a square confirmed, a call, a ruling, an
 * edit); marking your own card does not move it. The poll reads it from
 * the cache, so a quiet game costs no query, and flarum/realtime (when it
 * is installed and running) pushes it so open cards refetch at once.
 * A new game's first bump replaces a remembered "missing".
 */
class Pulse
{
    private const TTL = 86400;
    private const MISSING = -1;

    public function __construct(private readonly Container $container, private readonly Cache $cache, private readonly Queue $queue)
    {
    }

    public function bump(Game $game): int
    {
        Game::query()->whereKey($game->id)->increment('revision');
        $game->revision = (int) Game::query()->whereKey($game->id)->value('revision');
        $this->cache->put(self::key($game->id), $game->revision, self::TTL);
        $this->broadcast($game);

        return $game->revision;
    }

    /** The cached revision; null for a game that doesn't exist (remembered too, so made-up ids cost no query). */
    public function revision(int $gameId): ?int
    {
        $revision = $this->cache->get(self::key($gameId));
        if ($revision === null) {
            $revision = (int) (Game::query()->whereKey($gameId)->value('revision') ?? self::MISSING);
            $this->cache->put(self::key($gameId), $revision, $revision === self::MISSING ? 300 : self::TTL);
        }

        return $revision === self::MISSING ? null : (int) $revision;
    }

    public function forget(int $gameId): void
    {
        $this->cache->forget(self::key($gameId));
    }

    /** Queued (BroadcastPulse), and only when flarum/realtime is there to carry it. */
    private function broadcast(Game $game): void
    {
        if (class_exists(\Pusher\Pusher::class) && $this->container->bound(\Pusher\Pusher::class)) {
            $this->queue->push(new BroadcastPulse($game->id, $game->revision));
        }
    }

    private static function key(int $gameId): string
    {
        return 'ernestdefoe-bingo.rev.'.$gameId;
    }
}
