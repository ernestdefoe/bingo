<?php

namespace ErnestDefoe\Bingo\Api;

use Carbon\Carbon;
use ErnestDefoe\Bingo\Card;
use ErnestDefoe\Bingo\Claim;
use ErnestDefoe\Bingo\Game;
use ErnestDefoe\Bingo\Player;
use ErnestDefoe\Bingo\Presenter;
use ErnestDefoe\Bingo\Settings;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/bingo            live, upcoming and recent games, and the winners board
 * GET /api/bingo?past=12    the next page of past games
 *
 * A fixed number of queries: three game lists, their discussions, winners,
 * the viewer's cards, the board and one users query.
 */
class HubController implements RequestHandlerInterface
{
    public const PAGE = 12;

    public function __construct(private readonly Presenter $presenter)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::VIEW);

        $now = Carbon::now();
        $offset = max(0, (int) Arr::get($request->getQueryParams(), 'past', 0));
        $visible = fn () => Game::query()->whereVisibleTo($actor)->with('discussion');

        $past = $visible()->where('bingo_games.ends_at', '<=', $now)->orderByDesc('bingo_games.ends_at')->orderByDesc('bingo_games.id')
            ->offset($offset)->limit(self::PAGE + 1)->get();
        $more = $past->count() > self::PAGE;
        $past = $past->take(self::PAGE);

        if ($offset > 0) {
            return new JsonResponse($this->lists(['past' => $past], $actor) + ['more' => $more]);
        }

        $live = $visible()->where('bingo_games.starts_at', '<=', $now)->where('bingo_games.ends_at', '>', $now)
            ->orderByDesc('bingo_games.players_count')->limit(24)->get();
        $upcoming = $visible()->where('bingo_games.starts_at', '>', $now)->orderBy('bingo_games.starts_at')->limit(24)->get();

        $board = Claim::query()
            ->whereIn('game_id', Game::query()->whereVisibleTo($actor)->select('bingo_games.id'))
            ->where('status', Claim::ACCEPTED)
            ->whereNotNull('place')
            ->groupBy('user_id')
            ->select('user_id')
            ->selectRaw('SUM(CASE WHEN place = 1 THEN 1 ELSE 0 END) AS gold')
            ->selectRaw('SUM(CASE WHEN place = 2 THEN 1 ELSE 0 END) AS silver')
            ->selectRaw('SUM(CASE WHEN place = 3 THEN 1 ELSE 0 END) AS bronze')
            ->orderByDesc('gold')
            ->orderByDesc('silver')
            ->orderByDesc('bronze')
            ->limit(10)
            ->toBase()
            ->get()
            ->map(fn ($row) => [(int) $row->user_id, (int) $row->gold, (int) $row->silver, (int) $row->bronze])
            ->all();

        $data = $this->lists(['live' => $live, 'upcoming' => $upcoming, 'past' => $past], $actor, array_column($board, 0));

        return new JsonResponse($data + [
            'more' => $more,
            'board' => $board,
            'canCreate' => $actor->can(Settings::CREATE),
        ]);
    }

    /**
     * @param array<string, Collection<int, Game>> $lists
     * @param list<int> $extraUsers
     * @return array<string, mixed>
     */
    private function lists(array $lists, User $actor, array $extraUsers = []): array
    {
        $all = (new Collection($lists))->flatten(1);
        $winners = $this->presenter->winners($all);
        $mine = $actor->isGuest() || $all->isEmpty() ? []
            : Player::query()->where('user_id', $actor->id)->whereIn('game_id', $all->pluck('id'))->pluck('marks', 'game_id')->all();

        $users = $extraUsers;
        $out = [];
        foreach ($lists as $name => $games) {
            $out[$name] = $games->map(function (Game $game) use ($actor, $winners, $mine, &$users) {
                $users[] = $game->user_id;
                foreach ($winners[$game->id] ?? [] as [, $userId]) {
                    $users[] = $userId;
                }

                return $this->presenter->game($game, $actor) + [
                    'winners' => $winners[$game->id] ?? [],
                    'mine' => isset($mine[$game->id]) ? Card::away((int) $mine[$game->id]) : null,
                ];
            })->values()->all();
        }

        return $out + ['users' => $this->presenter->users($users), 'now' => Carbon::now()->toIso8601String()];
    }
}
