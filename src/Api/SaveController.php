<?php

namespace ErnestDefoe\Bingo\Api;

use Carbon\Carbon;
use ErnestDefoe\Bingo\Claim;
use ErnestDefoe\Bingo\Game;
use ErnestDefoe\Bingo\Presenter;
use ErnestDefoe\Bingo\Pulse;
use ErnestDefoe\Bingo\Referee;
use ErnestDefoe\Bingo\Settings;
use ErnestDefoe\Bingo\Square;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST  /api/bingo/games        a new game
 * PATCH /api/bingo/games/{id}   the host or a moderator edits it, or {action: start|end}
 *
 * Cards are dealt by pool position, so once anyone has a card the pool is
 * locked: labels can still be corrected, squares cannot come or go.
 */
class SaveController implements RequestHandlerInterface
{
    use Finds;

    public const MIN_SQUARES = 24;
    public const MAX_SQUARES = 150;
    public const MAX_DAYS = 14;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Presenter $presenter,
        private readonly Referee $referee,
        private readonly Pulse $pulse,
        private readonly RateLimit $limit,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $body = (array) $request->getParsedBody();

        if ($request->getMethod() === 'POST') {
            return $this->create($actor, $body);
        }

        $game = $this->game($request, $actor);
        if (! $game) {
            return Errors::make(404, 'bingo_not_found');
        }
        if (! $game->canManage($actor)) {
            return Errors::make(403, 'bingo_not_host');
        }

        return $this->update($game, $actor, $body);
    }

    /** @param array<string, mixed> $body */
    private function create(User $actor, array $body): ResponseInterface
    {
        $actor->assertCan(Settings::CREATE);

        $discussion = null;
        if (! empty($body['discussionId'])) {
            $discussion = Discussion::query()->whereVisibleTo($actor)->find((int) $body['discussionId']);
            if (! $discussion || ! $actor->can('reply', $discussion)) {
                return Errors::make(403, 'bingo_cannot_attach');
            }
        }

        $title = self::text($body['title'] ?? null, 100);
        $free = filter_var($body['freeCenter'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $labels = self::labels($body['squares'] ?? null);
        $starts = self::time($body['startsAt'] ?? null);
        $ends = self::time($body['endsAt'] ?? null);

        if (! $title) {
            return Errors::make(422, 'bingo_no_title');
        }
        $error = self::checkLabels($labels, $free)
            ?? (! $starts || ! $ends || $starts < Carbon::now()->subMinutes(10) ? 'bingo_bad_times' : self::checkTimes($starts, $ends));
        if ($error) {
            return Errors::make(422, $error);
        }
        if ($this->limit->exceeded($actor, 'create', 5) || ($this->limit->dailyExceeded($actor, 'create', 20) && ! $actor->can(Settings::MODERATE))) {
            return Errors::make(429, 'bingo_slow_down');
        }

        $game = new Game();
        $game->user_id = $actor->id;
        $game->discussion_id = $discussion?->id;
        $game->title = $title;
        $game->description = self::description($body['description'] ?? null);
        $game->seed = bin2hex(random_bytes(16));
        $game->free_center = $free;
        $game->free_label = self::text($body['freeLabel'] ?? null, 30);
        $game->places = self::places($body['places'] ?? 1);
        $game->verify = in_array($body['verify'] ?? null, Settings::VERIFY_MODES, true) ? $body['verify'] : Settings::VERIFY_CONFIRMED;
        $game->starts_at = $starts->max(Carbon::now());
        $game->ends_at = $ends;

        $this->db->transaction(function () use ($game, $labels) {
            $game->save();
            $this->insertSquares($game, $labels, 0);
        });

        // A poll may have remembered this id as missing a moment ago.
        $this->pulse->forget($game->id);
        $this->referee->postGame($game, count($labels));

        return new JsonResponse($this->presenter->state($game, $actor), 201);
    }

    /** @param array<string, mixed> $body */
    private function update(Game $game, User $actor, array $body): ResponseInterface
    {
        $now = Carbon::now();
        $action = $body['action'] ?? null;

        if ($action === 'start' && $game->status() === Game::UPCOMING) {
            $game->starts_at = $now;
        } elseif ($action === 'end' && $game->status() !== Game::FINISHED) {
            $game->finished_at = $now;
            $game->finished_by_host = true;
        } elseif ($action === null) {
            if (array_key_exists('title', $body)) {
                $title = self::text($body['title'], 100);
                if (! $title) {
                    return Errors::make(422, 'bingo_no_title');
                }
                $game->title = $title;
            }
            if (array_key_exists('description', $body)) {
                $game->description = self::description($body['description']);
            }
            if (array_key_exists('freeLabel', $body)) {
                $game->free_label = self::text($body['freeLabel'], 30);
            }
            if (array_key_exists('verify', $body) && in_array($body['verify'], Settings::VERIFY_MODES, true)) {
                $game->verify = $body['verify'];
            }
            // The free centre changes how many squares a card takes: only before anyone has one.
            if (array_key_exists('freeCenter', $body) && $game->players_count === 0) {
                $game->free_center = filter_var($body['freeCenter'], FILTER_VALIDATE_BOOLEAN);
            }
            if (array_key_exists('places', $body)) {
                $game->places = self::places($body['places']);
            }

            $starts = array_key_exists('startsAt', $body) && $game->status() === Game::UPCOMING ? self::time($body['startsAt']) : $game->starts_at;
            $ends = array_key_exists('endsAt', $body) ? self::time($body['endsAt']) : $game->ends_at;
            $error = ! $starts || ! $ends ? 'bingo_bad_times' : self::checkTimes($starts, $ends);
            if ($error) {
                return Errors::make(422, $error);
            }
            $game->starts_at = $starts;
            if (! $ends->equalTo($game->ends_at)) {
                $game->ends_at = $ends;
                // Given more time after the host ended it: open again (standings finish it again if every place is won).
                if ($ends > $now && $game->finished_by_host) {
                    $game->finished_at = null;
                    $game->finished_by_host = false;
                }
            }

            $error = array_key_exists('squares', $body) ? $this->saveSquares($game, $body['squares'])
                : ($game->isDirty('free_center') ? self::checkLabels(Square::query()->where('game_id', $game->id)->pluck('label')->all(), $game->free_center) : null);
            if ($error) {
                return Errors::make(422, $error);
            }
        } else {
            return Errors::make(422, 'bingo_bad_action');
        }

        $this->db->transaction(function () use ($game) {
            $changes = $game->getDirty();
            $game->lock()->forceFill($changes)->save();
            $this->referee->standings($game);
        });
        $this->referee->results($game);
        $this->pulse->bump($game);

        return new JsonResponse($this->presenter->state($game, $actor));
    }

    /**
     * Before anyone plays: the pool is replaced. After: only labels of
     * existing squares change.
     */
    private function saveSquares(Game $game, mixed $rows): ?string
    {
        if (! is_array($rows)) {
            return 'bingo_bad_squares';
        }

        if ($game->players_count > 0) {
            $squares = Square::query()->where('game_id', $game->id)->get()->keyBy('id');
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                $label = self::text($row['label'] ?? null, 80);
                if (isset($squares[$id]) && $label && $label !== $squares[$id]->label) {
                    $squares[$id]->label = $label;
                    $squares[$id]->save();
                }
            }

            return null;
        }

        $labels = self::labels(array_map(fn ($row) => is_array($row) ? ($row['label'] ?? null) : $row, $rows));
        if ($error = self::checkLabels($labels, $game->free_center)) {
            return $error;
        }

        $this->db->transaction(function () use ($game, $labels) {
            Square::query()->where('game_id', $game->id)->delete();
            $this->insertSquares($game, $labels, 0);
        });

        return null;
    }

    /** @param list<string> $labels */
    private function insertSquares(Game $game, array $labels, int $from): void
    {
        $rows = [];
        foreach ($labels as $i => $label) {
            $rows[] = ['game_id' => $game->id, 'position' => $from + $i, 'label' => $label, 'marks_count' => 0];
        }
        foreach (array_chunk($rows, 50) as $chunk) {
            Square::query()->insert($chunk);
        }
    }

    /**
     * Trimmed, blank lines dropped, repeats (ignoring case) dropped.
     *
     * @return list<string>
     */
    private static function labels(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\R/u', $value) ?: [];
        }
        if (! is_array($value)) {
            return [];
        }

        $seen = [];
        $labels = [];
        foreach ($value as $label) {
            $label = is_string($label) ? trim(preg_replace('/\s+/u', ' ', $label) ?? '') : '';
            $key = mb_strtolower($label);
            if ($label === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $labels[] = $label;
        }

        return $labels;
    }

    /** @param list<string> $labels */
    private static function checkLabels(array $labels, bool $free): ?string
    {
        if (count($labels) < self::MIN_SQUARES + ($free ? 0 : 1)) {
            return $free ? 'bingo_too_few_squares' : 'bingo_too_few_squares_no_free';
        }
        if (count($labels) > self::MAX_SQUARES) {
            return 'bingo_too_many_squares';
        }
        foreach ($labels as $label) {
            if (mb_strlen($label) > 80) {
                return 'bingo_square_too_long';
            }
        }

        return null;
    }

    private static function checkTimes(Carbon $starts, Carbon $ends): ?string
    {
        if ($ends <= $starts) {
            return 'bingo_ends_before_start';
        }
        if ($starts->diffInDays($ends) > self::MAX_DAYS) {
            return 'bingo_too_long';
        }
        if ($starts > Carbon::now()->addYear()) {
            return 'bingo_bad_times';
        }

        return null;
    }

    private static function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value)) {
            return null;
        }
        try {
            return Carbon::parse($value)->utc()->startOfMinute();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function places(mixed $value): int
    {
        return max(1, min(3, (int) $value));
    }

    private static function description(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim(Str::limit(str_replace("\r\n", "\n", $value), 1000, ''));

        return $value === '' ? null : $value;
    }
}
