<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * "Bingo!" calls. status: pending, accepted or rejected. Places come from
 * ranked_at (Standings): calls that became winning at the same moment share
 * a place. `squares` is the line's square ids, kept so everyone can see
 * what was called and the host can check it.
 */
return Migration::createTable('bingo_claims', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('game_id');
    $table->unsignedInteger('user_id');
    $table->unsignedTinyInteger('line');
    $table->string('squares', 100);
    $table->string('status', 10)->default('pending');
    $table->unsignedTinyInteger('place')->nullable();
    $table->dateTime('ranked_at')->nullable();
    $table->boolean('auto')->default(false);
    $table->string('note', 200)->nullable();
    $table->unsignedInteger('resolved_by')->nullable();
    $table->dateTime('resolved_at')->nullable();
    $table->dateTime('reviewed_at')->nullable();
    $table->unsignedInteger('disputes_count')->default(0);
    $table->unsignedInteger('post_id')->nullable();
    $table->dateTime('created_at');

    $table->index(['game_id', 'status']);
    $table->index('user_id');
    $table->foreign('game_id')->references('id')->on('bingo_games')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
