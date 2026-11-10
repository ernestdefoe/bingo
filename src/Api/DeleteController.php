<?php

namespace ErnestDefoe\Bingo\Api;

use ErnestDefoe\Bingo\Pulse;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** DELETE /api/bingo/games/{id}: squares, cards, calls and disputes go with it (foreign keys). */
class DeleteController implements RequestHandlerInterface
{
    use Finds;

    public function __construct(private readonly Pulse $pulse)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $game = $this->game($request, $actor);
        if (! $game) {
            return Errors::make(404, 'bingo_not_found');
        }
        if (! $game->canManage($actor)) {
            return Errors::make(403, 'bingo_not_host');
        }

        $this->pulse->bump($game);
        $game->delete();
        $this->pulse->forget($game->id);

        return new EmptyResponse(204);
    }
}
