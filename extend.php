<?php

/*
 * Bingo: play along with live events.
 *
 * A game is a pool of squares; every member who joins is dealt their own
 * 5×5 card from it, seeded by the game's secret and their id, so cards are
 * fixed and nobody picks theirs. Members mark what happens, call Bingo,
 * and the server checks the call against the stored card (Referee): the
 * browser never decides a win. Calls, the start and the results are event
 * posts in the game's discussion.
 *
 * Open cards watch one number per game (Pulse): pushed by flarum/realtime
 * when it is there, polled from the cache when not. The hub page, the game
 * page and the forms are lazy chunks; forum.js only wires them up.
 */

use ErnestDefoe\Bingo\Api;
use ErnestDefoe\Bingo\DiscussionGames;
use ErnestDefoe\Bingo\Game;
use ErnestDefoe\Bingo\Gdpr\BingoData;
use ErnestDefoe\Bingo\Http\PageContent;
use ErnestDefoe\Bingo\Notification\ResultBlueprint;
use ErnestDefoe\Bingo\Notification\ReviewBlueprint;
use ErnestDefoe\Bingo\Post\BingoPost;
use ErnestDefoe\Bingo\Settings;
use Flarum\Api\Context;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;
use Flarum\Extend;
use Flarum\Gdpr\Extend\UserData;
use Flarum\User\Event\Deleted;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // 🚨 Not optional: the pages and forms are chunks in here, and undeclared chunks 404.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/bingo', 'bingo', PageContent::class)
        ->route('/bingo/{id:\d+(?:-[^/]*)?}', 'bingo.game', PageContent::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('api'))
        ->get('/bingo', 'bingo.hub', Api\HubController::class)
        ->post('/bingo/games', 'bingo.games.create', Api\SaveController::class)
        ->get('/bingo/games/{id}', 'bingo.games.show', Api\ShowController::class)
        ->patch('/bingo/games/{id}', 'bingo.games.update', Api\SaveController::class)
        ->delete('/bingo/games/{id}', 'bingo.games.delete', Api\DeleteController::class)
        ->get('/bingo/games/{id}/pulse', 'bingo.games.pulse', Api\PulseController::class)
        ->post('/bingo/games/{id}/join', 'bingo.games.join', Api\JoinController::class)
        ->post('/bingo/games/{id}/mark', 'bingo.games.mark', Api\MarkController::class)
        ->post('/bingo/games/{id}/call', 'bingo.games.call', Api\CallController::class)
        ->post('/bingo/claims/{id}', 'bingo.claims.rule', Api\RuleController::class)
        ->post('/bingo/claims/{id}/dispute', 'bingo.claims.dispute', Api\DisputeController::class)
        ->post('/bingo/squares/{id}', 'bingo.squares.confirm', Api\SquareController::class),

    (new Extend\Post())
        ->type(BingoPost::class),

    (new Extend\Notification())
        ->type(ReviewBlueprint::class, ['alert'])
        ->type(ResultBlueprint::class, ['alert']),

    // Only on a discussion's own page: lists never pay for it.
    (new Extend\ApiResource(DiscussionResource::class))
        ->fields(fn () => [
            Schema\Arr::make('bingoGames')
                ->visible(fn (Discussion $discussion, Context $context) => $context->showing(DiscussionResource::class) && $context->getActor()->can(Settings::VIEW))
                ->get(fn (Discussion $discussion, Context $context) => resolve(DiscussionGames::class)($discussion, $context->getActor())),
            Schema\Boolean::make('canStartBingo')
                ->visible(fn (Discussion $discussion, Context $context) => $context->showing(DiscussionResource::class))
                ->get(fn (Discussion $discussion, Context $context) => $context->getActor()->can(Settings::CREATE) && $context->getActor()->can('reply', $discussion)),
        ]),

    // The viewer's own permissions: already loaded, so no query.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('bingoCanView')
                ->get(fn ($forum, Context $context) => $context->getActor()->can(Settings::VIEW)),
            Schema\Boolean::make('bingoCanPlay')
                ->get(fn ($forum, Context $context) => $context->getActor()->can(Settings::PLAY)),
            Schema\Boolean::make('bingoCanCreate')
                ->get(fn ($forum, Context $context) => $context->getActor()->can(Settings::CREATE)),
        ]),

    (new Extend\Settings())
        ->default(Settings::NAV, true)
        ->serializeToForum('bingoNav', Settings::NAV, 'boolval'),

    // The foreign keys do this too, where the database enforces them (SQLite may not).
    (new Extend\Event())
        ->listen(Deleted::class, function (Deleted $event) {
            \ErnestDefoe\Bingo\Player::query()->where('user_id', $event->user->id)->delete();
            \ErnestDefoe\Bingo\Claim::query()->where('user_id', $event->user->id)->delete();
            \ErnestDefoe\Bingo\Dispute::query()->where('user_id', $event->user->id)->delete();
            Game::query()->where('user_id', $event->user->id)->update(['user_id' => null]);
        }),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new UserData())->addType(BingoData::class),
        ]),
];
