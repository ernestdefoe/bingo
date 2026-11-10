<?php

namespace ErnestDefoe\Bingo;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Http\SlugManager;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Collection;

/**
 * What the browser gets. One game's whole state is a fixed handful of
 * queries however many play (the "closest" board reads one integer per
 * player, cached 10 s per game); people come as one compact list, pushed into the forum's store
 * so avatars and names render the usual way.
 */
class Presenter
{
    public const CALLS = 60;
    public const LEADERS = 8;
    public const BOARD_TTL = 10;

    public function __construct(private readonly SlugManager $slugs, private readonly Cache $cache)
    {
    }

    /** @return array<string, mixed> */
    public function state(Game $game, User $actor): array
    {
        $squares = Square::query()->where('game_id', $game->id)->orderBy('position')->get();
        $player = $actor->isGuest() ? null : Player::query()->where('game_id', $game->id)->where('user_id', $actor->id)->first();

        $claims = Claim::query()->where('game_id', $game->id)->orderByDesc('id')->limit(self::CALLS)->get();
        $disputed = $actor->isGuest() || $claims->isEmpty() ? []
            : Dispute::query()->whereIn('claim_id', $claims->pluck('id'))->where('user_id', $actor->id)->pluck('claim_id')->all();
        [$leaders, $near] = $this->board($game);

        $manage = $game->canManage($actor);
        $userIds = array_merge([$game->user_id], $claims->pluck('user_id')->all(), array_keys($leaders));

        return [
            'game' => $this->game($game, $actor) + [
                'description' => $game->description,
                'freeLabel' => $game->free_label,
                'canManage' => $manage,
                'canReferee' => $manage && ! $player,
                'locked' => $game->players_count > 0,
            ],
            'squares' => $squares->map(fn (Square $square) => [
                $square->id,
                $square->label,
                $square->confirmed_at ? 1 : 0,
                $square->marks_count,
            ])->all(),
            'me' => $player ? ['card' => $player->cells(), 'marks' => $player->marks] : null,
            'claims' => $claims->map(fn (Claim $claim) => [
                'id' => $claim->id,
                'user' => $claim->user_id,
                'line' => $claim->line,
                'squares' => $claim->squareIds(),
                'status' => $claim->status,
                'place' => $claim->place,
                'auto' => $claim->auto,
                'note' => $claim->note,
                'disputes' => $claim->disputes_count,
                'reviewed' => $claim->reviewed_at !== null,
                'disputed' => in_array($claim->id, $disputed, true),
                'at' => $claim->created_at->toIso8601String(),
            ])->all(),
            'leaders' => array_map(fn ($userId, $n) => [$userId, $n], array_keys($leaders), $leaders),
            'near' => $near,
            'users' => $this->users($userIds),
            'now' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * "Closest to Bingo": cells each player's best line still needs, from
     * the marks alone. The same for every viewer, so it is cached for a few
     * seconds per game: a crowd polling a busy game reads one integer per
     * player once, not once each.
     *
     * @return array{0: array<int, int>, 1: array{int, int, int}}
     */
    private function board(Game $game): array
    {
        return $this->cache->remember('ernestdefoe-bingo.board.'.$game->id, self::BOARD_TTL, function () use ($game) {
            $winners = Claim::query()->where('game_id', $game->id)->where('status', Claim::ACCEPTED)->pluck('user_id')->all();
            $away = [];
            $near = [0, 0, 0];
            foreach (Player::query()->where('game_id', $game->id)->orderBy('id')->pluck('marks', 'user_id') as $userId => $marks) {
                $n = Card::away((int) $marks);
                if ($n >= 1 && $n <= 3) {
                    $near[$n - 1]++;
                }
                if ($n > 0 && ! in_array($userId, $winners, true)) {
                    $away[(int) $userId] = $n;
                }
            }
            asort($away);

            return [array_slice($away, 0, self::LEADERS, true), $near];
        });
    }

    /**
     * A game as a list or a discussion shows it.
     *
     * @return array<string, mixed>
     */
    public function game(Game $game, User $actor, ?Discussion $discussion = null): array
    {
        $discussion ??= $game->discussion_id ? $game->discussion : null;

        return [
            'id' => $game->id,
            'slug' => $game->slug(),
            'title' => $game->title,
            'status' => $game->status(),
            'startsAt' => $game->starts_at->toIso8601String(),
            // When it ends (or ended): what every clock and status reads. The schedule is for the editor.
            'endsAt' => $game->endedAt()->toIso8601String(),
            'scheduledEndsAt' => $game->ends_at->toIso8601String(),
            'endedEarly' => $game->finished_at !== null,
            'freeCenter' => $game->free_center,
            'places' => $game->places,
            'verify' => $game->verify,
            'revision' => $game->revision,
            'players' => $game->players_count,
            'host' => $game->user_id,
            'discussion' => $discussion ? [
                'id' => $discussion->id,
                'slug' => $this->slugs->forResource(Discussion::class)->toSlug($discussion),
                'title' => $discussion->title,
            ] : null,
        ];
    }

    /**
     * @param array<int|null> $ids
     * @return list<array<string, mixed>>
     */
    public function users(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (! $ids) {
            return [];
        }

        $slugger = $this->slugs->forResource(User::class);

        return User::query()->whereIn('id', $ids)->get()->map(fn (User $user) => [
            'id' => $user->id,
            'username' => $user->username,
            'displayName' => $user->display_name,
            'slug' => $slugger->toSlug($user),
            'avatarUrl' => $user->avatar_url,
        ])->values()->all();
    }

    /**
     * Winners of the given games, place order.
     *
     * @param Collection<int, Game> $games
     * @return array<int, list<array{0: int, 1: int}>>
     */
    public function winners(Collection $games): array
    {
        $ids = $games->filter(fn (Game $game) => $game->status() === Game::FINISHED || $game->status() === Game::LIVE)->pluck('id');
        if ($ids->isEmpty()) {
            return [];
        }

        $winners = [];
        foreach (Claim::query()->whereIn('game_id', $ids)->where('status', Claim::ACCEPTED)->whereNotNull('place')
            ->orderBy('place')->orderBy('id')->get(['game_id', 'user_id', 'place']) as $claim) {
            $winners[$claim->game_id][] = [$claim->place, $claim->user_id];
        }

        return $winners;
    }
}
