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
 * Each of them runs in a transaction that first takes the game's row lock
 * (Game::lock) and re-reads the game, so two calls, or a call and a
 * ruling, never decide places from the same stale picture.
 *
 * Places: an accepted call ranks at the moment its line became true, the
 * later of the call and the last confirmation of its squares. Calls that
 * rank at the same moment share a place, like a tie in a bingo hall.
 */
class Referee
{
    public const MAX_CALLS = 5;

    /** On the honor system, a call this soon after the start can't be real: nothing has happened yet. */
    public const TRUST_GRACE = 120;

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
            $game->lock();
            if (! $game->isLive()) {
                return $game->status() === Game::UPCOMING ? 'bingo_not_started' : 'bingo_over';
            }
            if ($game->verify === Settings::VERIFY_TRUST && Carbon::now() < $game->starts_at->copy()->addSeconds(self::TRUST_GRACE)) {
                return 'bingo_too_soon';
            }

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

            $moved = $claim->status === Claim::ACCEPTED ? $this->standings($game) : [];

            return ['claim' => $claim, 'moved' => $moved, 'labels' => array_map(fn ($id) => $squares[$id]->label ?? '', $claim->squareIds())];
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
        $this->tellMoved($game, $result['moved'], [$claim->id], null);

        $this->results($game);
        $this->pulse->bump($game);

        return $claim;
    }

    /** The host or a moderator says a square happened (or takes it back). */
    public function confirm(Game $game, Square $square, bool $confirmed, User $actor): void
    {
        $result = $this->db->transaction(function () use ($game, $square, $confirmed, $actor) {
            $game->lock();
            $square = Square::query()->whereKey($square->id)->lockForUpdate()->firstOrFail();
            if ($confirmed === ($square->confirmed_at !== null)) {
                return null;
            }

            $square->confirmed_at = $confirmed ? Carbon::now() : null;
            $square->confirmed_by = $confirmed ? $actor->id : null;
            $square->save();

            if ($game->verify !== Settings::VERIFY_CONFIRMED) {
                return ['ruled' => [], 'moved' => []];
            }

            $squares = Square::query()->where('game_id', $game->id)->get()->keyBy('id');
            $ruled = [];
            $status = $confirmed ? Claim::PENDING : Claim::ACCEPTED;

            foreach (Claim::query()->where('game_id', $game->id)->where('status', $status)->get() as $claim) {
                if (! in_array($square->id, $claim->squareIds(), true)) {
                    continue;
                }
                if ($confirmed && $this->confirmed($claim->squareIds(), $squares)) {
                    // Every call this confirmation completes ranks at the same moment.
                    $claim->status = Claim::ACCEPTED;
                    $claim->auto = true;
                    $claim->ranked_at = $this->rankTime($claim, $squares);
                    $claim->save();
                    $ruled[] = $claim;
                } elseif (! $confirmed && $claim->auto) {
                    // Taken back: a call that only stood on this square waits again.
                    $claim->status = Claim::PENDING;
                    $claim->auto = false;
                    $claim->ranked_at = null;
                    $claim->place = null;
                    $claim->save();
                    $ruled[] = $claim;
                }
            }

            return ['ruled' => $ruled, 'moved' => $ruled ? $this->standings($game) : []];
        });

        if ($result === null) {
            return;
        }

        foreach ($result['ruled'] as $claim) {
            $claim->refresh();
            $this->updatePost($claim);
            if ($claim->status === Claim::ACCEPTED) {
                $this->tell($game, $claim, null);
            }
        }
        $this->tellMoved($game, $result['moved'], array_map(fn (Claim $claim) => $claim->id, $result['ruled']), null);

        $this->results($game);
        $this->pulse->bump($game);
    }

    /** accept, reject, or uphold (clear the disputes and keep the call as it is). */
    public function rule(Game $game, Claim $claim, string $action, ?string $note, User $actor): void
    {
        $result = $this->db->transaction(function () use ($game, $claim, $action, $note, $actor) {
            $game->lock();
            $claim = Claim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();
            $before = $claim->status;
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

            return ['claim' => $claim, 'before' => $before, 'moved' => $this->standings($game)];
        });

        $claim = $result['claim'];
        $claim->refresh();
        $this->updatePost($claim);
        if ($claim->status !== $result['before']) {
            $this->tell($game, $claim, $actor);
        }
        $this->tellMoved($game, $result['moved'], [$claim->id], $actor);

        $this->results($game);
        $this->pulse->bump($game);
    }

    public function dispute(Game $game, Claim $claim, User $actor, ?string $reason): void
    {
        $this->db->transaction(function () use ($game, $claim, $actor, $reason) {
            $game->lock();

            $dispute = new Dispute();
            $dispute->claim_id = $claim->id;
            $dispute->user_id = $actor->id;
            $dispute->reason = $reason;
            $dispute->created_at = Carbon::now();
            $dispute->save();

            Claim::query()->whereKey($claim->id)->increment('disputes_count');
        });

        if ($game->user_id && $game->user_id !== $actor->id && $game->host) {
            $this->notifications->sync(new ReviewBlueprint($game, $claim, $actor, 'dispute'), [$game->host]);
        }

        $this->pulse->bump($game);
    }

    /**
     * Places from ranked_at; ties share a place. Call inside a transaction
     * that holds the game's lock.
     *
     * When every place has a winner the game is finished (finished_at);
     * when a ruling frees a place again before the scheduled end, it opens
     * again, unless the host ended it. Returns the accepted calls whose
     * place moved, so their players can be told.
     *
     * @return list<Claim>
     */
    public function standings(Game $game): array
    {
        $claims = Claim::query()
            ->where('game_id', $game->id)
            ->where('status', Claim::ACCEPTED)
            ->orderBy('ranked_at')
            ->orderBy('id')
            ->get();

        $moved = [];
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
                $moved[] = $claim;
                if ($claim->post_id) {
                    $this->updatePost($claim);
                }
            }
        }

        $filled = $rank >= $game->places;
        $now = Carbon::now();
        if ($filled && ! $game->finished_at && $game->isLive()) {
            $game->finished_at = $now;
            $game->finished_by_host = false;
            $game->save();
        } elseif (! $filled && $game->finished_at && ! $game->finished_by_host && $now < $game->ends_at) {
            $game->finished_at = null;
            $game->save();
        }

        return $moved;
    }

    /**
     * The results post in the game's discussion: written once the game is
     * over, then kept true in place (by id) whenever standings change, a
     * call is still being checked, or a freed place reopens the game.
     *
     * $onlyIfMissing is the cheap path for page views: a game that simply
     * ran out of time has nobody else to post its results.
     */
    public function results(Game $game, bool $onlyIfMissing = false): void
    {
        if (! $game->discussion_id || ($onlyIfMissing && ($game->results_post_id || $game->status() !== Game::FINISHED))) {
            return;
        }
        if (! $game->results_post_id && $game->status() !== Game::FINISHED) {
            return;
        }

        $this->db->transaction(function () use ($game) {
            $game->lock();
            $finished = $game->status() === Game::FINISHED;
            $post = $game->results_post_id ? Post::query()->where('type', BingoPost::$type)->find($game->results_post_id) : null;
            if (! $post && (! $finished || ! $game->players_count)) {
                return;
            }

            $content = $this->resultsContent($game, $finished);
            if ($post instanceof BingoPost) {
                if ($post->content != $content) {
                    $post->content = $content;
                    $post->save();
                }

                return;
            }

            $post = BingoPost::reply($game->discussion_id, $game->user_id, $content);
            $post->save();
            $game->results_post_id = $post->id;
            $game->save();
        });
    }

    /** @return array<string, mixed> */
    private function resultsContent(Game $game, bool $finished): array
    {
        $claims = Claim::query()
            ->where('game_id', $game->id)
            ->whereIn('status', [Claim::ACCEPTED, Claim::PENDING])
            ->orderBy('place')
            ->orderBy('id')
            ->get(['user_id', 'place', 'status']);
        $placed = $claims->where('status', Claim::ACCEPTED)->whereNotNull('place');
        // Names as they are now: the post must read right without loading anyone.
        $users = User::query()->whereIn('id', $placed->pluck('user_id'))->get()->keyBy('id');

        return [
            'kind' => 'results',
            'game' => $game->id,
            'title' => $game->title,
            'winners' => $placed->map(fn (Claim $claim) => [$claim->place, $claim->user_id, $users[$claim->user_id]->display_name ?? ''])->values()->all(),
            'pending' => $claims->where('status', Claim::PENDING)->count(),
            'open' => ! $finished,
            'players' => $game->players_count,
        ];
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
     * Players whose place moved because of someone else's call or ruling
     * (2nd became 1st after a rejection): told once, only them.
     *
     * @param list<Claim> $moved
     * @param list<int> $told claims already told about by the caller
     */
    private function tellMoved(Game $game, array $moved, array $told, ?User $by): void
    {
        foreach ($moved as $claim) {
            if (! in_array($claim->id, $told, true) && $claim->place) {
                $this->tell($game, $claim, $by);
            }
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
