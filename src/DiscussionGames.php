<?php

namespace ErnestDefoe\Bingo;

use Flarum\Discussion\Discussion;
use Flarum\User\User;

/**
 * The games a discussion page shows above its posts: live ones first,
 * then upcoming, then the latest finished. One query, only on the
 * discussion's own page (never in lists).
 */
class DiscussionGames
{
    public function __construct(private readonly Presenter $presenter, private readonly Referee $referee)
    {
    }

    /** @return list<array<string, mixed>> */
    public function __invoke(Discussion $discussion, User $actor): array
    {
        $games = Game::query()->where('discussion_id', $discussion->id)->orderByDesc('ends_at')->limit(6)->get();
        $order = [Game::LIVE => 0, Game::UPCOMING => 1, Game::FINISHED => 2];

        return $games
            ->each(fn (Game $game) => $this->referee->results($game, true))
            ->sortBy(fn (Game $game) => $order[$game->status()])
            ->map(fn (Game $game) => $this->presenter->game($game, $actor, $discussion))
            ->values()
            ->all();
    }
}
