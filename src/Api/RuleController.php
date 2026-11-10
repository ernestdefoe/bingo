<?php

namespace ErnestDefoe\Bingo\Api;

use ErnestDefoe\Bingo\Claim;
use ErnestDefoe\Bingo\Game;
use ErnestDefoe\Bingo\Presenter;
use ErnestDefoe\Bingo\Referee;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/bingo/claims/{id} {action: accept|reject|uphold, note}: the
 * host or a moderator rules on a call.
 */
class RuleController implements RequestHandlerInterface
{
    use Finds;

    public function __construct(private readonly Referee $referee, private readonly Presenter $presenter)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $claim = Claim::query()->find((int) Arr::get($request->getQueryParams(), 'id'));
        $game = $claim ? Game::query()->whereVisibleTo($actor)->find($claim->game_id) : null;
        if (! $claim || ! $game) {
            return Errors::make(404, 'bingo_not_found');
        }
        if (! $game->canManage($actor)) {
            return Errors::make(403, 'bingo_not_host');
        }
        if (! $game->canReferee($actor)) {
            return Errors::make(403, 'bingo_player_cannot_referee');
        }

        $body = (array) $request->getParsedBody();
        $action = $body['action'] ?? null;
        if (! in_array($action, ['accept', 'reject', 'uphold'], true)) {
            return Errors::make(422, 'bingo_bad_ruling');
        }
        if ($action === 'accept' && Claim::query()->where('game_id', $game->id)->where('user_id', $claim->user_id)
            ->where('status', Claim::ACCEPTED)->whereKeyNot($claim->id)->exists()) {
            return Errors::make(422, 'bingo_already_won');
        }

        $this->referee->rule($game, $claim, $action, self::text($body['note'] ?? null, 200), $actor);

        return new JsonResponse($this->presenter->state($game, $actor));
    }
}
