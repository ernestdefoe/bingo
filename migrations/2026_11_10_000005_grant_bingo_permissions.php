<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

// Anyone may watch, members play and host, moderators referee every game.
return Migration::addPermissions([
    'bingo.view' => Group::GUEST_ID,
    'bingo.play' => Group::MEMBER_ID,
    'bingo.create' => Group::MEMBER_ID,
    'bingo.moderate' => Group::MODERATOR_ID,
]);
