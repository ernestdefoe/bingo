<?php

namespace ErnestDefoe\Bingo\Api;

use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;

/** Things one member may do so many times a minute: new games, disputes, taps. */
class RateLimit
{
    public function __construct(private readonly Cache $cache)
    {
    }

    public function exceeded(User $actor, string $kind, int $perMinute): bool
    {
        $key = 'ernestdefoe-bingo.rate.'.$kind.':'.$actor->id.':'.intdiv(time(), 60);
        $this->cache->add($key, 0, 120);

        return (int) $this->cache->increment($key) > $perMinute;
    }
}
