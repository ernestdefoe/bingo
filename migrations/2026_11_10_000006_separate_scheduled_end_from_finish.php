<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * ends_at stays the scheduled end. A game that is over early (every place
 * won, or the host ended it) gets finished_at, so a ruling that frees a
 * place can reopen it until its real end; finished_by_host says the host
 * ended it on purpose (a ruling never reopens that). The results post is
 * kept by id and updated in place when standings change.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('bingo_games', function (Blueprint $table) {
            $table->dateTime('finished_at')->nullable()->after('ends_at');
            $table->boolean('finished_by_host')->default(false)->after('finished_at');
            $table->unsignedInteger('results_post_id')->nullable()->after('finished_by_host');
        });
        $schema->table('bingo_games', function (Blueprint $table) {
            $table->dropColumn(['ended_early', 'results_posted_at']);
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('bingo_games', function (Blueprint $table) {
            $table->boolean('ended_early')->default(false);
            $table->dateTime('results_posted_at')->nullable();
        });
        $schema->table('bingo_games', function (Blueprint $table) {
            $table->dropColumn(['finished_at', 'finished_by_host', 'results_post_id']);
        });
    },
];
