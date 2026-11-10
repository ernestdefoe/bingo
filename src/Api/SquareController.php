<?php

namespace ErnestDefoe\Bingo\Api;

use ErnestDefoe\Bingo\Game;
use ErnestDefoe\Bingo\Presenter;
use ErnestDefoe\Bingo\Referee;
use ErnestDefoe\Bingo\Square;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/bingo/squares/{id} {confirmed}: the host or a moderator says
 * it happened. With "check calls against confirmed squares", this is what
 * turns waiting calls into wins.
 */
class SquareController implements RequestHandlerInterface
{
    public function __construct(private readonly Referee $referee, private readonly Presenter $presenter)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $square = Square::query()->find((int) Arr::get($request->getQueryParams(), 'id'));
        $game = $square ? Game::query()->whereVisibleTo($actor)->find($square->game_id) : null;
        if (! $square || ! $game) {
            return Errors::make(404, 'bingo_not_found');
        }
        if (! $game->canManage($actor)) {
            return Errors::make(403, 'bingo_not_host');
        }
        if ($game->status() === Game::UPCOMING) {
            return Errors::make(422, 'bingo_not_started');
        }

        $confirmed = filter_var(((array) $request->getParsedBody())['confirmed'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $this->referee->confirm($game, $square, $confirmed, $actor);

        return new JsonResponse($this->presenter->state($game, $actor));
    }
}
