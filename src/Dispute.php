<?php

namespace ErnestDefoe\Bingo;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $claim_id
 * @property int $user_id
 * @property string|null $reason
 * @property Carbon $created_at
 */
class Dispute extends AbstractModel
{
    protected $table = 'bingo_disputes';

    protected $casts = [
        'id' => 'integer',
        'claim_id' => 'integer',
        'user_id' => 'integer',
        'created_at' => 'datetime',
    ];
}
