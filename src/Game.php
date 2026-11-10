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
 * @property Carbon|null $finished_at
 * @property bool $finished_by_host
 * @property int|null $results_post_id
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
        'finished_at' => 'datetime',
        'finished_by_host' => 'boolean',
        'results_post_id' => 'integer',
        'revision' => 'integer',
        'players_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function status(): string
    {
        $now = Carbon::now();
        if ($now < $this->starts_at && ! $this->finished_at) {
            return self::UPCOMING;
        }

        return $now < $this->endedAt() ? self::LIVE : self::FINISHED;
    }

    /** When it is (or was) over: early if every place was won or the host ended it, else on schedule. */
    public function endedAt(): Carbon
    {
        return $this->finished_at && $this->finished_at < $this->ends_at ? $this->finished_at : $this->ends_at;
    }

    /**
     * Take the row lock for the rest of the transaction and bring this copy
     * up to date: a call, a confirmation and a ruling on the same game then
     * run one after another, each seeing what the one before decided.
     */
    public function lock(): static
    {
        $fresh = static::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();
        $this->setRawAttributes($fresh->getAttributes(), true);

        return $this;
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

    /**
     * Confirms squares and rules on calls: the host or a moderator who has
     * no card in this game. A moderator who plays is a player here, and
     * can't confirm their own squares or rule on their own call.
     */
    public function canReferee(User $actor, ?bool $playing = null): bool
    {
        $playing ??= ! $actor->isGuest() && Player::query()->where('game_id', $this->id)->where('user_id', $actor->id)->exists();

        return $this->canManage($actor) && ! $playing;
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
