<?php

namespace ErnestDefoe\Bingo;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $game_id
 * @property int $position
 * @property string $label
 * @property Carbon|null $confirmed_at
 * @property int|null $confirmed_by
 * @property int $marks_count
 */
class Square extends AbstractModel
{
    protected $table = 'bingo_squares';

    protected $casts = [
        'id' => 'integer',
        'game_id' => 'integer',
        'position' => 'integer',
        'confirmed_at' => 'datetime',
        'confirmed_by' => 'integer',
        'marks_count' => 'integer',
    ];
}
