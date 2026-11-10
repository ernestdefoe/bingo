<?php

namespace ErnestDefoe\Bingo;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;

/**
 * The one number every open card watches. It goes up when something all
 * viewers should see changes (a square confirmed, a call, a ruling, an
 * edit); marking your own card does not move it. The poll reads it from
 * the cache, so a quiet game costs no query, and flarum/realtime (when it
 * is installed and running) pushes it so open cards refetch at once.
 */
class Pulse
{
    private const TTL = 86400;

    public function __construct(private readonly Container $container, private readonly Cache $cache)
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

    public function revision(int $gameId): ?int
    {
        $revision = $this->cache->get(self::key($gameId));
        if ($revision === null) {
            $revision = Game::query()->whereKey($gameId)->value('revision');
            if ($revision === null) {
                return null;
            }
            $this->cache->put(self::key($gameId), (int) $revision, self::TTL);
        }

        return (int) $revision;
    }

    public function forget(int $gameId): void
    {
        $this->cache->forget(self::key($gameId));
    }

    /**
     * flarum/realtime binds its Pusher client; without it (or with the
     * daemon down) this does nothing and the poll keeps cards fresh. Only
     * the id and the number go out: what changed is fetched with the
     * viewer's own permissions.
     */
    private function broadcast(Game $game): void
    {
        if (! class_exists(\Pusher\Pusher::class) || ! $this->container->bound(\Pusher\Pusher::class)) {
            return;
        }

        try {
            $this->container->make(\Pusher\Pusher::class)->trigger('public', 'bingo', ['id' => $game->id, 'rev' => $game->revision]);
        } catch (\Throwable) {
            // Realtime is down: the poll covers it.
        }
    }

    private static function key(int $gameId): string
    {
        return 'ernestdefoe-bingo.rev.'.$gameId;
    }
}
