<?php

namespace ErnestDefoe\Bingo;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $game_id
 * @property int $user_id
 * @property string $card
 * @property int $marks
 * @property Carbon $joined_at
 */
class Player extends AbstractModel
{
    protected $table = 'bingo_players';

    protected $casts = [
        'id' => 'integer',
        'game_id' => 'integer',
        'user_id' => 'integer',
        'marks' => 'integer',
        'joined_at' => 'datetime',
    ];

    /** @return list<int> */
    public function cells(): array
    {
        return Card::decode($this->card);
    }
}
