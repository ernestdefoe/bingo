<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('bingo_disputes', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('claim_id');
    $table->unsignedInteger('user_id');
    $table->string('reason', 200)->nullable();
    $table->dateTime('created_at');

    $table->unique(['claim_id', 'user_id']);
    $table->foreign('claim_id')->references('id')->on('bingo_claims')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
