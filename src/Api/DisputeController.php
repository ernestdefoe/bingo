<?php

namespace ErnestDefoe\Bingo\Api;

use ErnestDefoe\Bingo\Claim;
use ErnestDefoe\Bingo\Dispute;
use ErnestDefoe\Bingo\Game;
use ErnestDefoe\Bingo\Player;
use ErnestDefoe\Bingo\Presenter;
use ErnestDefoe\Bingo\Referee;
use ErnestDefoe\Bingo\Settings;
use Flarum\Http\RequestUtil;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/bingo/claims/{id}/dispute {reason}: "that didn't happen". Any
 * other player may dispute a call once, until the host has reviewed it;
 * the call keeps its standing until the host rules.
 */
class DisputeController implements RequestHandlerInterface
{
    use Finds;

    public function __construct(
        private readonly Referee $referee,
        private readonly Presenter $presenter,
        private readonly RateLimit $limit,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan(Settings::PLAY);

        $claim = Claim::query()->find((int) Arr::get($request->getQueryParams(), 'id'));
        $game = $claim ? Game::query()->whereVisibleTo($actor)->find($claim->game_id) : null;
        if (! $claim || ! $game) {
            return Errors::make(404, 'bingo_not_found');
        }

        if ($claim->user_id === $actor->id || $claim->status === Claim::REJECTED || $claim->reviewed_at
            || ! Player::query()->where('game_id', $game->id)->where('user_id', $actor->id)->exists()) {
            return Errors::make(422, 'bingo_cannot_dispute');
        }
        if (Dispute::query()->where('claim_id', $claim->id)->where('user_id', $actor->id)->exists()) {
            return Errors::make(422, 'bingo_disputed_already');
        }
        if ($this->limit->exceeded($actor, 'dispute', 5)) {
            return Errors::make(429, 'bingo_slow_down');
        }

        try {
            $this->referee->dispute($game, $claim, $actor, self::text(((array) $request->getParsedBody())['reason'] ?? null, 200));
        } catch (QueryException) {
            // A double tap that raced the first: the unique index kept it to one.
            return Errors::make(422, 'bingo_disputed_already');
        }

        return new JsonResponse($this->presenter->state($game, $actor));
    }
}
