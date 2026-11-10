<?php

namespace ErnestDefoe\Bingo;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $game_id
 * @property int $user_id
 * @property int $line
 * @property string $squares
 * @property string $status
 * @property int|null $place
 * @property Carbon|null $ranked_at
 * @property bool $auto
 * @property string|null $note
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property Carbon|null $reviewed_at
 * @property int $disputes_count
 * @property int|null $post_id
 * @property Carbon $created_at
 */
class Claim extends AbstractModel
{
    public const PENDING = 'pending';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';

    protected $table = 'bingo_claims';

    protected $casts = [
        'id' => 'integer',
        'game_id' => 'integer',
        'user_id' => 'integer',
        'line' => 'integer',
        'place' => 'integer',
        'ranked_at' => 'datetime',
        'auto' => 'boolean',
        'resolved_by' => 'integer',
        'resolved_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'disputes_count' => 'integer',
        'post_id' => 'integer',
        'created_at' => 'datetime',
    ];

    /** @return list<int> */
    public function squareIds(): array
    {
        return $this->squares === '' ? [] : array_map('intval', explode(',', $this->squares));
    }
}
