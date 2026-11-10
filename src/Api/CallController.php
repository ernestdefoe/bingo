<?php

namespace ErnestDefoe\Bingo\Api;

use ErnestDefoe\Bingo\Player;
use ErnestDefoe\Bingo\Presenter;
use ErnestDefoe\Bingo\Referee;
use ErnestDefoe\Bingo\Settings;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/bingo/games/{id}/call: "Bingo!" The browser sends nothing but
 * the wish; the server finds the line on the stored card and marks, and
 * Referee decides whether it counts.
 */
class CallController implements RequestHandlerInterface
{
    use Finds;

    public function __construct(private readonly Referee $referee, private readonly Presenter $presenter)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan(Settings::PLAY);

        $game = $this->game($request, $actor);
        if (! $game) {
            return Errors::make(404, 'bingo_not_found');
        }
        if (! $game->isLive()) {
            return Errors::make(422, $game->status() === 'upcoming' ? 'bingo_not_started' : 'bingo_over');
        }

        $player = Player::query()->where('game_id', $game->id)->where('user_id', $actor->id)->first();
        if (! $player) {
            return Errors::make(403, 'bingo_not_player');
        }

        $claim = $this->referee->call($game, $player, $actor);
        if (is_string($claim)) {
            return Errors::make(422, $claim);
        }

        return new JsonResponse(['claim' => $claim->id] + $this->presenter->state($game, $actor), 201);
    }
}
