<?php

namespace ErnestDefoe\Bingo\Api;

use ErnestDefoe\Bingo\Game;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

trait Finds
{
    /** The game in the path, if the actor may see it. */
    private function game(ServerRequestInterface $request, ?User $actor = null): ?Game
    {
        $id = (int) Arr::get($request->getQueryParams(), 'id');
        $actor ??= RequestUtil::getActor($request);

        return $id > 0 ? Game::query()->whereVisibleTo($actor)->find($id) : null;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
