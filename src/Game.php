<?php

namespace ErnestDefoe\Bingo;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $discussion_id
 * @property string $title
 * @property string|null $description
 * @property string $seed
 * @property bool $free_center
 * @property string|null $free_label
 * @property int $places
 * @property string $verify
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property bool $ended_early
 * @property Carbon|null $results_posted_at
 * @property int $revision
 * @property int $players_count
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $host
 * @property-read Discussion|null $discussion
 */
class Game extends AbstractModel
{
    public const UPCOMING = 'upcoming';
    public const LIVE = 'live';
    public const FINISHED = 'finished';

    protected $table = 'bingo_games';

    public $timestamps = true;

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'discussion_id' => 'integer',
        'free_center' => 'boolean',
        'places' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'ended_early' => 'boolean',
        'results_posted_at' => 'datetime',
        'revision' => 'integer',
        'players_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function status(): string
    {
        $now = Carbon::now();

        return $now < $this->starts_at ? self::UPCOMING : ($now < $this->ends_at ? self::LIVE : self::FINISHED);
    }

    public function isLive(): bool
    {
        return $this->status() === self::LIVE;
    }

    /** The host or a moderator: confirms squares, rules on calls, edits and ends the game. */
    public function canManage(User $actor): bool
    {
        return ! $actor->isGuest() && ($actor->can(Settings::MODERATE) || $actor->id === $this->user_id);
    }

    public function slug(): string
    {
        return \Illuminate\Support\Str::slug($this->title) ?: 'game';
    }

    /**
     * Games the actor may see: anyone with the view permission sees a
     * standalone game; a game in a discussion is as visible as that
     * discussion (tags, private discussions, hidden ones).
     *
     * @param Builder<Game> $query
     */
    public function scopeWhereVisibleTo(Builder $query, User $actor): void
    {
        if (! $actor->can(Settings::VIEW)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $query) use ($actor) {
            $query->whereNull('bingo_games.discussion_id')
                ->orWhereIn('bingo_games.discussion_id', Discussion::query()->whereVisibleTo($actor)->select('discussions.id'));
        });
    }

    /** @return HasMany<Square, $this> */
    public function squares(): HasMany
    {
        return $this->hasMany(Square::class)->orderBy('position');
    }

    /** @return BelongsTo<User, $this> */
    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Discussion, $this> */
    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class);
    }
}
