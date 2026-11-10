<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * A game: a pool of squares (bingo_squares) played between starts_at and
 * ends_at, optionally in a watch-along discussion. `seed` deals every card
 * (Card::deal), so nobody can choose theirs. `revision` goes up whenever
 * something every viewer should see changes (a square confirmed, a call, a
 * ruling): the poll compares one number. A game that is over early (all
 * places won, or the host ended it) gets ends_at = now.
 */
return Migration::createTable('bingo_games', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('user_id')->nullable();
    $table->unsignedInteger('discussion_id')->nullable()->index();
    $table->string('title', 100);
    $table->text('description')->nullable();
    $table->char('seed', 32);
    $table->boolean('free_center')->default(true);
    $table->string('free_label', 30)->nullable();
    $table->unsignedTinyInteger('places')->default(1);
    $table->string('verify', 10)->default('confirmed');
    $table->dateTime('starts_at')->index();
    $table->dateTime('ends_at')->index();
    $table->boolean('ended_early')->default(false);
    $table->dateTime('results_posted_at')->nullable();
    $table->unsignedInteger('revision')->default(0);
    $table->unsignedInteger('players_count')->default(0);
    $table->timestamps();

    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
    $table->foreign('discussion_id')->references('id')->on('discussions')->cascadeOnDelete();
});
