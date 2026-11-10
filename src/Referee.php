<?php

namespace ErnestDefoe\Bingo;

use Carbon\Carbon;
use ErnestDefoe\Bingo\Notification\ResultBlueprint;
use ErnestDefoe\Bingo\Notification\ReviewBlueprint;
use ErnestDefoe\Bingo\Post\BingoPost;
use Flarum\Notification\NotificationSyncer;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * Every rule of the game that changes standings lives here: calls, square
 * confirmations, rulings, disputes, places and the end of a game. Each
 * one is checked on the server against the stored card and marks; the
 * browser only ever says "I have Bingo", never which line or that it won.
 *
 * Places: an accepted call ranks at the moment its line became true, the
 * later of the call and the last confirmation of its squares. Calls that
 * rank at the same moment share a place, like a tie in a bingo hall.
 */
class Referee
{
    public const MAX_CALLS = 5;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Pulse $pulse,
        private readonly NotificationSyncer $notifications,
    ) {
    }

    /** "Bingo!" Returns the call, or an error code. */
    public function call(Game $game, Player $player, User $actor): Claim|string
    {
        $result = $this->db->transaction(function () use ($game, $player, $actor) {
            $player = Player::query()->whereKey($player->id)->lockForUpdate()->firstOrFail();
            $mine = Claim::query()->where('game_id', $game->id)->where('user_id', $actor->id)->get(['id', 'status', 'line']);

            if ($mine->contains('status', Claim::ACCEPTED)) {
                return 'bingo_already_won';
            }
            if ($mine->contains('status', Claim::PENDING)) {
                return 'bingo_call_waiting';
            }
            if ($mine->count() >= self::MAX_CALLS) {
                return 'bingo_too_many_calls';
            }

            $refused = $mine->where('status', Claim::REJECTED)->pluck('line')->all();
            $lines = array_values(array_diff(Card::completeLines($player->marks), $refused));
            if (! $lines) {
                return Card::completeLines($player->marks) ? 'bingo_line_refused' : 'bingo_no_line';
            }

            $card = $player->cells();
            $squares = Square::query()->where('game_id', $game->id)->get()->keyBy('id');

            // A line the host has already confirmed wins over one still waiting.
            usort($lines, fn ($a, $b) => (int) $this->confirmed(Card::lineSquares($card, $b), $squares) <=> (int) $this->confirmed(Card::lineSquares($card, $a), $squares));
            $line = $lines[0];

            $claim = new Claim();
            $claim->game_id = $game->id;
            $claim->user_id = $actor->id;
            $claim->line = $line;
            $claim->squares = implode(',', Card::lineSquares($card, $line));
            $claim->status = Claim::PENDING;
            $claim->created_at = Carbon::now();

            if ($game->verify === Settings::VERIFY_TRUST
                || ($game->verify === Settings::VERIFY_CONFIRMED && $this->confirmed($claim->squareIds(), $squares))) {
                $claim->status = Claim::ACCEPTED;
                $claim->auto = true;
                $claim->ranked_at = $this->rankTime($claim, $squares);
            }
            $claim->save();

            if ($claim->status === Claim::ACCEPTED) {
                $this->standings($game);
            }

            return ['claim' => $claim, 'labels' => array_map(fn ($id) => $squares[$id]->label ?? '', $claim->squareIds())];
        });

        if (is_string($result)) {
            return $result;
        }

        $claim = $result['claim'];
        $claim->refresh();
        $this->postCall($game, $claim, $result['labels']);

        if ($game->user_id && $game->user_id !== $actor->id && $game->host) {
            $this->notifications->sync(new ReviewBlueprint($game, $claim, $actor, 'call'), [$game->host]);
        }

        $this->finalize($game);
        $this->pulse->bump($game);

        return $claim;
    }

    /** The host or a moderator says a square happened (or takes it back). */
    public function confirm(Game $game, Square $square, bool $confirmed, User $actor): void
    {
        if ($confirmed === ($square->confirmed_at !== null)) {
            return;
        }

        $changed = $this->db->transaction(function () use ($game, $square, $confirmed, $actor) {
            $square->confirmed_at = $confirmed ? Carbon::now() : null;
            $square->confirmed_by = $confirmed ? $actor->id : null;
            $square->save();

            if ($game->verify !== Settings::VERIFY_CONFIRMED) {
                return [];
            }

            $squares = Square::query()->where('game_id', $game->id)->get()->keyBy('id');
            $changed = [];
            $status = $confirmed ? Claim::PENDING : Claim::ACCEPTED;
            $claims = Claim::query()->where('game_id', $game->id)->where('status', $status)->get();

            foreach ($claims as $claim) {
                if (! in_array($square->id, $claim->squareIds(), true)) {
                    continue;
                }
                if ($confirmed && $this->confirmed($claim->squareIds(), $squares)) {
                    // Every call this confirmation completes ranks at the same moment.
                    $claim->status = Claim::ACCEPTED;
                    $claim->auto = true;
                    $claim->ranked_at = $this->rankTime($claim, $squares);
                    $claim->save();
                    $changed[] = $claim;
                } elseif (! $confirmed && $claim->auto) {
                    // Taken back: a call that only stood on this square waits again.
                    $claim->status = Claim::PENDING;
                    $claim->auto = false;
                    $claim->ranked_at = null;
                    $claim->place = null;
                    $claim->save();
                    $changed[] = $claim;
                }
            }

            if ($changed) {
                $this->standings($game);
            }

            return $changed;
        });

        foreach ($changed as $claim) {
            $claim->refresh();
            $this->updatePost($claim);
            if ($claim->status === Claim::ACCEPTED) {
                $this->tell($game, $claim, null);
            }
        }

        $this->finalize($game);
        $this->pulse->bump($game);
    }

    /** accept, reject, or uphold (clear the disputes and keep the call as it is). */
    public function rule(Game $game, Claim $claim, string $action, ?string $note, User $actor): void
    {
        $before = $claim->status;

        $this->db->transaction(function () use ($game, $claim, $action, $note, $actor) {
            $now = Carbon::now();
            if ($action === 'accept' && $claim->status !== Claim::ACCEPTED) {
                $squares = Square::query()->where('game_id', $game->id)->get()->keyBy('id');
                $claim->status = Claim::ACCEPTED;
                $claim->auto = false;
                $claim->ranked_at = $this->rankTime($claim, $squares);
                $claim->note = null;
            } elseif ($action === 'reject') {
                $claim->status = Claim::REJECTED;
                $claim->auto = false;
                $claim->ranked_at = null;
                $claim->place = null;
                $claim->note = $note;
            }

            $claim->resolved_by = $actor->id;
            $claim->resolved_at = $now;
            $claim->reviewed_at = $now;
            $claim->disputes_count = 0;
            $claim->save();
            Dispute::query()->where('claim_id', $claim->id)->delete();

            $this->standings($game);
        });

        $claim->refresh();
        $this->updatePost($claim);
        if ($claim->status !== $before) {
            $this->tell($game, $claim, $actor);
        }

        $this->finalize($game);
        $this->pulse->bump($game);
    }

    public function dispute(Game $game, Claim $claim, User $actor, ?string $reason): void
    {
        $dispute = new Dispute();
        $dispute->claim_id = $claim->id;
        $dispute->user_id = $actor->id;
        $dispute->reason = $reason;
        $dispute->created_at = Carbon::now();
        $dispute->save();

        Claim::query()->whereKey($claim->id)->increment('disputes_count');

        if ($game->user_id && $game->user_id !== $actor->id && $game->host) {
            $this->notifications->sync(new ReviewBlueprint($game, $claim, $actor, 'dispute'), [$game->host]);
        }

        $this->pulse->bump($game);
    }

    /**
     * Places from ranked_at; ties share a place. When every place has a
     * winner the game is over: ends_at becomes now.
     */
    public function standings(Game $game): void
    {
        $claims = Claim::query()
            ->where('game_id', $game->id)
            ->where('status', Claim::ACCEPTED)
            ->orderBy('ranked_at')
            ->orderBy('id')
            ->get();

        $rank = 0;
        $last = null;
        foreach ($claims as $claim) {
            $moment = $claim->ranked_at?->getTimestamp();
            if ($moment !== $last) {
                $rank++;
                $last = $moment;
            }
            $place = $rank <= $game->places ? $rank : null;
            if ($claim->place !== $place) {
                $claim->place = $place;
                $claim->save();
                if ($claim->post_id) {
                    $this->updatePost($claim);
                }
            }
        }

        if ($rank >= $game->places && $game->isLive()) {
            $game->ends_at = Carbon::now();
            $game->ended_early = true;
            $game->save();
        }
    }

    /**
     * Once a game is over, its results go into the discussion, once. Called
     * after anything that can end a game and when an ended game is viewed
     * (a game that simply ran out of time has nobody to post it).
     */
    public function finalize(Game $game): void
    {
        if ($game->results_posted_at || $game->status() !== Game::FINISHED) {
            return;
        }

        $now = Carbon::now();
        $won = Game::query()->whereKey($game->id)->whereNull('results_posted_at')->update(['results_posted_at' => $now]);
        $game->results_posted_at = $now;
        if (! $won || ! $game->discussion_id || ! $game->players_count) {
            return;
        }

        $claims = Claim::query()
            ->where('game_id', $game->id)
            ->where('status', Claim::ACCEPTED)
            ->whereNotNull('place')
            ->orderBy('place')
            ->orderBy('id')
            ->get(['user_id', 'place']);
        // Names as they are now: the post must read right without loading anyone.
        $users = User::query()->whereIn('id', $claims->pluck('user_id'))->get()->keyBy('id');
        $winners = $claims->map(fn (Claim $claim) => [$claim->place, $claim->user_id, $users[$claim->user_id]->display_name ?? ''])->all();

        BingoPost::reply($game->discussion_id, $game->user_id, [
            'kind' => 'results',
            'game' => $game->id,
            'title' => $game->title,
            'winners' => $winners,
            'players' => $game->players_count,
        ])->save();
    }

    public function postGame(Game $game, int $squares): void
    {
        if (! $game->discussion_id) {
            return;
        }

        BingoPost::reply($game->discussion_id, $game->user_id, [
            'kind' => 'game',
            'game' => $game->id,
            'title' => $game->title,
            'starts' => $game->starts_at->toIso8601String(),
            'ends' => $game->ends_at->toIso8601String(),
            'squares' => $squares,
        ])->save();
    }

    /** @param list<string> $labels */
    private function postCall(Game $game, Claim $claim, array $labels): void
    {
        if (! $game->discussion_id) {
            return;
        }

        $post = BingoPost::reply($game->discussion_id, $claim->user_id, [
            'kind' => 'call',
            'game' => $game->id,
            'title' => $game->title,
            'claim' => $claim->id,
            'line' => $claim->line,
            'labels' => $labels,
            'status' => $claim->status,
            'place' => $claim->place,
        ]);
        $post->save();

        $claim->post_id = $post->id;
        $claim->save();
    }

    private function updatePost(Claim $claim): void
    {
        $post = $claim->post_id ? Post::query()->where('type', BingoPost::$type)->find($claim->post_id) : null;
        if (! $post instanceof BingoPost) {
            return;
        }

        $post->content = array_merge((array) $post->content, ['status' => $claim->status, 'place' => $claim->place]);
        $post->save();
    }

    /** The caller hears how a call that waited (or was overruled) came out. */
    private function tell(Game $game, Claim $claim, ?User $by): void
    {
        $player = User::query()->find($claim->user_id);
        if ($player instanceof User && $player->id !== $by?->id) {
            $this->notifications->sync(new ResultBlueprint($game, $claim, $player, $by), [$player]);
        }
    }

    /**
     * @param list<int> $ids
     * @param Collection<int, Square> $squares
     */
    private function confirmed(array $ids, Collection $squares): bool
    {
        foreach ($ids as $id) {
            if (! isset($squares[$id]) || ! $squares[$id]->confirmed_at) {
                return false;
            }
        }

        return true;
    }

    /** @param Collection<int, Square> $squares */
    private function rankTime(Claim $claim, Collection $squares): Carbon
    {
        $moment = $claim->created_at->copy();
        foreach ($claim->squareIds() as $id) {
            $at = $squares[$id]->confirmed_at ?? null;
            if ($at && $at > $moment) {
                $moment = $at->copy();
            }
        }

        return $moment;
    }
}
