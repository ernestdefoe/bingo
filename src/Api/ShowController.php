<?php

namespace ErnestDefoe\Bingo\Api;

use ErnestDefoe\Bingo\Presenter;
use ErnestDefoe\Bingo\Referee;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /api/bingo/games/{id}: the whole game as the viewer may see it. */
class ShowController implements RequestHandlerInterface
{
    use Finds;

    public function __construct(private readonly Presenter $presenter, private readonly Referee $referee)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $game = $this->game($request, $actor);
        if (! $game) {
            return Errors::make(404, 'bingo_not_found');
        }

        $this->referee->finalize($game);

        return new JsonResponse($this->presenter->state($game, $actor));
    }
}
