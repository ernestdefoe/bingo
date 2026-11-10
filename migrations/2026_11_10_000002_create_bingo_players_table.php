<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * A member's card: the 25 square ids Card::deal gave them (0 = the free
 * centre), stored so lines can be checked in SQL-free PHP without dealing
 * again, and `marks`, a 25-bit mask of the cells they marked.
 */
return Migration::createTable('bingo_players', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('game_id');
    $table->unsignedInteger('user_id');
    $table->string('card', 400);
    $table->unsignedInteger('marks')->default(0);
    $table->dateTime('joined_at');

    $table->unique(['game_id', 'user_id']);
    $table->index('user_id');
    $table->foreign('game_id')->references('id')->on('bingo_games')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
