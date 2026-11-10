<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * The pool. Cards are dealt by position, so squares are only added or
 * removed before anyone has a card; labels can be corrected any time.
 * marks_count is how many players have marked it ("the crowd"), kept by
 * the mark endpoint with the same atomic update that sets the bit.
 */
return Migration::createTable('bingo_squares', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('game_id');
    $table->unsignedSmallInteger('position');
    $table->string('label', 80);
    $table->dateTime('confirmed_at')->nullable();
    $table->unsignedInteger('confirmed_by')->nullable();
    $table->unsignedInteger('marks_count')->default(0);

    $table->index(['game_id', 'position']);
    $table->foreign('game_id')->references('id')->on('bingo_games')->cascadeOnDelete();
});
