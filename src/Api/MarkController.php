<?php

namespace ErnestDefoe\Bingo\Api;

use ErnestDefoe\Bingo\Card;
use ErnestDefoe\Bingo\Player;
use ErnestDefoe\Bingo\Settings;
use ErnestDefoe\Bingo\Square;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/bingo/games/{id}/mark {cell, on}: dab a cell or wipe it.
 *
 * The bit and the square's crowd count change in one guarded UPDATE each:
 * a double tap, or two tabs, can never count a mark twice or lose one.
 * Marks do not move the game's revision, so the busiest part of a game
 * sends nobody else a refetch.
 */
class MarkController implements RequestHandlerInterface
{
    use Finds;

    public function __construct(private readonly ConnectionInterface $db, private readonly RateLimit $limit)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        // Checked on every tap, not just on joining: a suspended member stops playing at once.
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

        $body = (array) $request->getParsedBody();
        $cell = filter_var($body['cell'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => Card::SIZE - 1]]);
        $square = $cell === false ? 0 : ($player->cells()[$cell] ?? 0);
        if ($square === 0) {
            return Errors::make(422, 'bingo_bad_cell');
        }
        if ($this->limit->exceeded($actor, 'mark', 120)) {
            return Errors::make(429, 'bingo_slow_down');
        }

        $on = filter_var($body['on'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $bit = Card::bit($cell);

        // $bit is an integer we computed, never input: safe to write into the SQL.
        $changed = Player::query()
            ->whereKey($player->id)
            ->whereRaw($on ? "(marks & $bit) = 0" : "(marks & $bit) <> 0")
            ->update(['marks' => $this->db->raw($on ? "marks + $bit" : "marks - $bit")]);

        if ($changed) {
            $count = Square::query()->whereKey($square);
            $on ? $count->increment('marks_count') : $count->where('marks_count', '>', 0)->decrement('marks_count');
        }

        return new JsonResponse(['marks' => (int) Player::query()->whereKey($player->id)->value('marks')]);
    }
}
