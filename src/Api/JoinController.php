<?php

namespace ErnestDefoe\Bingo\Api;

use Carbon\Carbon;
use ErnestDefoe\Bingo\Card;
use ErnestDefoe\Bingo\Game;
use ErnestDefoe\Bingo\Player;
use ErnestDefoe\Bingo\Presenter;
use ErnestDefoe\Bingo\Settings;
use ErnestDefoe\Bingo\Square;
use Flarum\Http\RequestUtil;
use Illuminate\Database\QueryException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/bingo/games/{id}/join: deal the member's card. The deal is
 * seeded (Card::deal), so asking twice gives the same card; late joiners
 * are welcome until the game is over.
 */
class JoinController implements RequestHandlerInterface
{
    use Finds;

    public function __construct(private readonly Presenter $presenter)
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
        if ($game->status() === Game::FINISHED) {
            return Errors::make(422, 'bingo_over');
        }

        if (! Player::query()->where('game_id', $game->id)->where('user_id', $actor->id)->exists()) {
            $pool = Square::query()->where('game_id', $game->id)->orderBy('position')->pluck('id')->all();

            $player = new Player();
            $player->game_id = $game->id;
            $player->user_id = $actor->id;
            $player->card = Card::encode(Card::deal($game->seed, $actor->id, $pool, $game->free_center));
            $player->marks = Card::initialMarks($game->free_center);
            $player->joined_at = Carbon::now();

            try {
                $player->save();
                Game::query()->whereKey($game->id)->increment('players_count');
                $game->players_count++;
            } catch (QueryException) {
                // A second tap that raced the first: the card exists, which is all we wanted.
            }
        }

        return new JsonResponse($this->presenter->state($game, $actor));
    }
}
