<?php

namespace ErnestDefoe\Bingo\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Bingo\Card;
use ErnestDefoe\Bingo\Claim;
use ErnestDefoe\Bingo\Game;
use ErnestDefoe\Bingo\Player;
use ErnestDefoe\Bingo\Referee;
use ErnestDefoe\Bingo\Square;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class GameTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-bingo');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'dana', 'email' => 'dana@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'sam', 'email' => 'sam@machine.local', 'is_email_confirmed' => 1],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Watch-along', 'created_at' => Carbon::now(), 'user_id' => 2, 'first_post_id' => 1, 'comment_count' => 1, 'last_post_number' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Kickoff!</p></t>', 'created_at' => Carbon::now(), 'number' => 1],
            ],
        ]);
    }

    /** @return array{int, array<string, mixed>|null} */
    private function api(string $method, string $path, ?int $as, array $body = []): array
    {
        $options = $as ? ['authenticatedAs' => $as] : [];
        if ($method !== 'GET') {
            $options['json'] = $body;
        }
        $response = $this->send($this->request($method, '/api/bingo'.$path, $options));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    /** @return array<string, mixed> */
    private function create(array $overrides = []): array
    {
        [$status, $body] = $this->api('POST', '/games', 2, $overrides + [
            'title' => 'Ad Bingo',
            'squares' => array_map(fn ($i) => "Square $i", range(1, 30)),
            'startsAt' => Carbon::now()->toIso8601String(),
            'endsAt' => Carbon::now()->addHours(3)->toIso8601String(),
            'discussionId' => 1,
        ]);
        $this->assertSame(201, $status, json_encode($body));

        return $body;
    }

    /** Marks a whole row of the member's card; returns the card. */
    private function fillRow(int $gameId, int $user, int $row): array
    {
        [, $state] = $this->api('POST', "/games/$gameId/join", $user);
        foreach (range($row * 5, $row * 5 + 4) as $cell) {
            if ($cell !== Card::CENTER) {
                $this->assertSame(200, $this->api('POST', "/games/$gameId/mark", $user, ['cell' => $cell, 'on' => true])[0]);
            }
        }

        return $state['me']['card'];
    }

    /** The honor system refuses calls in the first two minutes. */
    private function afterGrace(): void
    {
        Carbon::setTestNow(Carbon::now()->addSeconds(Referee::TRUST_GRACE + 1));
    }

    private function confirm(array $squareIds, int $as = 2): void
    {
        foreach ($squareIds as $id) {
            $this->assertSame(200, $this->api('POST', "/squares/$id", $as, ['confirmed' => true])[0]);
        }
    }

    #[Test]
    public function a_member_starts_a_game_in_a_discussion_and_it_is_posted_there()
    {
        $state = $this->create();

        $this->assertSame('live', $state['game']['status']);
        $this->assertCount(30, $state['squares']);
        $this->assertTrue($state['game']['canManage']);
        $post = Post::query()->where('discussion_id', 1)->where('type', 'bingo')->first();
        $this->assertSame('game', $post->content['kind']);

        $response = $this->send($this->request('GET', '/api/discussions/1', ['authenticatedAs' => 3]));
        $discussion = json_decode((string) $response->getBody(), true);
        $this->assertSame($state['game']['id'], $discussion['data']['attributes']['bingoGames'][0]['id']);
    }

    #[Test]
    public function a_pool_needs_24_different_squares_and_guests_cannot_host()
    {
        [$status, $body] = $this->api('POST', '/games', 2, [
            'title' => 'Short',
            'squares' => array_merge(array_fill(0, 10, 'Same'), array_map(fn ($i) => "S$i", range(1, 20))),
            'startsAt' => Carbon::now()->toIso8601String(),
            'endsAt' => Carbon::now()->addHour()->toIso8601String(),
        ]);
        $this->assertSame(422, $status);
        $this->assertSame('bingo_too_few_squares', $body['errors'][0]['code']);

        $this->assertNotSame(201, $this->api('POST', '/games', null, ['title' => 'x'])[0]);
        $this->assertSame(0, Game::query()->count());
    }

    #[Test]
    public function the_card_is_dealt_by_the_server_from_the_seed()
    {
        $game = $this->create()['game'];
        [$status, $state] = $this->api('POST', "/games/{$game['id']}/join", 3);
        $this->assertSame(200, $status);

        $model = Game::query()->find($game['id']);
        $pool = Square::query()->where('game_id', $model->id)->orderBy('position')->pluck('id')->all();
        $this->assertSame(Card::deal($model->seed, 3, $pool, true), $state['me']['card']);
        $this->assertSame(Card::initialMarks(true), $state['me']['marks']);

        // Joining again deals nothing new.
        $this->assertSame($state['me']['card'], $this->api('POST', "/games/{$game['id']}/join", 3)[1]['me']['card']);
        $this->assertSame(1, Game::query()->find($game['id'])->players_count);

        // The host referees.
        $this->assertSame('bingo_host_cannot_play', $this->api('POST', "/games/{$game['id']}/join", 2)[1]['errors'][0]['code']);

        // The centre is free, not a square to mark.
        $this->assertSame(422, $this->api('POST', "/games/{$game['id']}/mark", 3, ['cell' => Card::CENTER])[0]);
    }

    #[Test]
    public function a_call_without_a_full_line_is_refused()
    {
        $game = $this->create()['game'];
        $this->api('POST', "/games/{$game['id']}/join", 3);
        $this->api('POST', "/games/{$game['id']}/mark", 3, ['cell' => 0, 'on' => true]);

        [$status, $body] = $this->api('POST', "/games/{$game['id']}/call", 3);
        $this->assertSame(422, $status);
        $this->assertSame('bingo_no_line', $body['errors'][0]['code']);
    }

    #[Test]
    public function on_the_honor_system_the_first_bingo_wins_and_ends_the_game()
    {
        $game = $this->create(['verify' => 'trust'])['game'];
        $this->fillRow($game['id'], 3, 0);
        $this->afterGrace();

        [$status, $state] = $this->api('POST', "/games/{$game['id']}/call", 3);
        $this->assertSame(201, $status);
        $this->assertSame('accepted', $state['claims'][0]['status']);
        $this->assertSame(1, $state['claims'][0]['place']);
        $this->assertSame('finished', $state['game']['status']);

        $kinds = Post::query()->where('discussion_id', 1)->where('type', 'bingo')->orderBy('id')->get()->map(fn ($p) => $p->content['kind'])->all();
        $this->assertSame(['game', 'call', 'results'], $kinds);
        $results = Post::query()->where('type', 'bingo')->orderByDesc('id')->first();
        $this->assertSame([[1, 3, 'dana']], $results->content['winners']);

        // Over: no more marks.
        $this->assertSame(422, $this->api('POST', "/games/{$game['id']}/mark", 4, ['cell' => 1])[0]);
    }

    #[Test]
    public function confirmed_squares_verify_waiting_calls_in_the_order_they_came_true()
    {
        $game = $this->create(['places' => 2])['game'];
        $cardA = $this->fillRow($game['id'], 3, 0);
        $cardB = $this->fillRow($game['id'], 4, 1);

        [, $state] = $this->api('POST', "/games/{$game['id']}/call", 3);
        $this->assertSame('pending', $state['claims'][0]['status']);
        $this->api('POST', "/games/{$game['id']}/call", 4);

        $this->confirm(array_slice($cardA, 0, 5));
        $this->assertSame(1, Claim::query()->where('user_id', 3)->first()->place);
        $this->assertSame('pending', Claim::query()->where('user_id', 4)->first()->status);

        Carbon::setTestNow(Carbon::now()->addMinute());
        $this->confirm(array_values(array_diff(array_slice($cardB, 5, 5), array_slice($cardA, 0, 5))));
        $this->assertSame(2, Claim::query()->where('user_id', 4)->first()->place);
        $this->assertSame('finished', Game::query()->find($game['id'])->status());

        $this->assertSame(2, $this->database()->table('notifications')->where('type', 'bingoResult')->count());
        $this->assertSame(2, $this->database()->table('notifications')->where('type', 'bingoReview')->where('user_id', 2)->count());
    }

    #[Test]
    public function calls_that_came_true_at_the_same_moment_share_a_place()
    {
        $game = $this->create(['places' => 2])['game'];
        $model = Game::query()->find($game['id']);
        $moment = Carbon::now()->startOfSecond();
        foreach ([3, 4] as $user) {
            $claim = new Claim();
            $claim->forceFill(['game_id' => $model->id, 'user_id' => $user, 'line' => 0, 'squares' => '', 'status' => 'accepted', 'ranked_at' => $moment, 'created_at' => $moment])->save();
        }

        $this->app()->getContainer()->make(\ErnestDefoe\Bingo\Referee::class)->standings($model);

        $this->assertSame([1, 1], Claim::query()->orderBy('id')->pluck('place')->all());
        $this->assertSame('live', $model->status(), 'one place filled, two to win');
    }

    #[Test]
    public function taking_a_confirmation_back_puts_the_call_back_to_waiting()
    {
        $game = $this->create(['places' => 3])['game'];
        $card = $this->fillRow($game['id'], 3, 0);
        $line = array_slice($card, 0, 5);
        $this->confirm($line);
        $this->api('POST', "/games/{$game['id']}/call", 3);
        $this->assertSame('accepted', Claim::query()->first()->status);

        $this->assertSame(200, $this->api('POST', "/squares/{$line[0]}", 2, ['confirmed' => false])[0]);
        $claim = Claim::query()->first();
        $this->assertSame('pending', $claim->status);
        $this->assertNull($claim->place);
    }

    #[Test]
    public function players_dispute_and_only_the_host_rules()
    {
        $game = $this->create(['verify' => 'host', 'places' => 3])['game'];
        $this->fillRow($game['id'], 3, 0);
        $this->api('POST', "/games/{$game['id']}/join", 4);
        [, $state] = $this->api('POST', "/games/{$game['id']}/call", 3);
        $claim = $state['claims'][0]['id'];

        $this->assertSame(422, $this->api('POST', "/claims/$claim/dispute", 3)[0], 'not your own call');
        $this->assertSame(200, $this->api('POST', "/claims/$claim/dispute", 4, ['reason' => 'No puppy'])[0]);
        $this->assertSame(422, $this->api('POST', "/claims/$claim/dispute", 4)[0], 'once');
        $this->assertSame(1, Claim::query()->find($claim)->disputes_count);

        $this->assertSame(403, $this->api('POST', "/claims/$claim", 4, ['action' => 'accept'])[0]);
        [$status, $state] = $this->api('POST', "/claims/$claim", 2, ['action' => 'reject', 'note' => 'No puppy yet']);
        $this->assertSame(200, $status);
        $this->assertSame('rejected', $state['claims'][0]['status']);
        $this->assertSame(0, $state['claims'][0]['disputes']);

        // The same line can't be called again.
        [$status, $body] = $this->api('POST', "/games/{$game['id']}/call", 3);
        $this->assertSame('bingo_line_refused', $body['errors'][0]['code']);
    }

    #[Test]
    public function the_pulse_moves_when_something_everyone_sees_changes()
    {
        $game = $this->create(['verify' => 'trust', 'places' => 2])['game'];
        $before = $this->api('GET', "/games/{$game['id']}/pulse", null)[1]['rev'];

        $this->fillRow($game['id'], 3, 0);
        $this->assertSame($before, $this->api('GET', "/games/{$game['id']}/pulse", null)[1]['rev'], 'marks are private');
        $this->afterGrace();

        $this->api('POST', "/games/{$game['id']}/call", 3);
        $this->assertGreaterThan($before, $this->api('GET', "/games/{$game['id']}/pulse", null)[1]['rev']);
    }

    #[Test]
    public function a_game_is_as_visible_as_its_discussion()
    {
        $game = $this->create()['game'];
        Discussion::query()->whereKey(1)->update(['hidden_at' => Carbon::now(), 'hidden_user_id' => 1]);

        $this->assertSame(404, $this->api('GET', "/games/{$game['id']}", 3)[0]);
        [, $hub] = $this->api('GET', '', 3);
        $this->assertSame([], $hub['live']);

        $this->assertSame(200, $this->api('GET', "/games/{$game['id']}", 1)[0]);
    }

    #[Test]
    public function the_hub_lists_games_and_the_winners_board()
    {
        $game = $this->create(['verify' => 'trust', 'discussionId' => null])['game'];
        $this->create(['title' => 'Later', 'startsAt' => Carbon::now()->addDay()->toIso8601String(), 'endsAt' => Carbon::now()->addDay()->addHours(2)->toIso8601String()]);
        $this->fillRow($game['id'], 3, 2);
        $this->afterGrace();
        $this->api('POST', "/games/{$game['id']}/call", 3);

        [$status, $hub] = $this->api('GET', '', null);
        $this->assertSame(200, $status);
        $this->assertSame('Later', $hub['upcoming'][0]['title']);
        $this->assertSame($game['id'], $hub['past'][0]['id']);
        $this->assertSame([[1, 3]], $hub['past'][0]['winners']);
        $this->assertSame([[3, 1, 0, 0]], $hub['board']);
    }

    #[Test]
    public function an_honor_system_win_in_the_first_seconds_is_refused_and_a_rejected_win_reopens_the_game()
    {
        $game = $this->create(['verify' => 'trust'])['game'];
        $this->fillRow($game['id'], 3, 0);

        [$status, $body] = $this->api('POST', "/games/{$game['id']}/call", 3);
        $this->assertSame(422, $status);
        $this->assertSame('bingo_too_soon', $body['errors'][0]['code']);

        $this->afterGrace();
        [, $state] = $this->api('POST', "/games/{$game['id']}/call", 3);
        $this->assertSame('finished', $state['game']['status']);
        $results = Post::query()->find(Game::query()->find($game['id'])->results_post_id);
        $this->assertSame([[1, 3, 'dana']], $results->content['winners']);

        // The host throws the cheat out: the game is open again until its scheduled end, and the results post says so.
        [, $state] = $this->api('POST', "/claims/{$state['claims'][0]['id']}", 2, ['action' => 'reject', 'note' => 'Nothing had happened yet']);
        $this->assertSame('live', $state['game']['status']);
        $this->assertTrue($results->fresh()->content['open']);
        $this->api('POST', "/games/{$game['id']}/join", 4);
        $this->assertSame(200, $this->api('POST', "/games/{$game['id']}/mark", 4, ['cell' => 0, 'on' => true])[0], 'others play on');
    }

    #[Test]
    public function the_results_post_is_kept_true_in_place_and_says_when_calls_are_still_being_checked()
    {
        $game = $this->create(['verify' => 'host', 'places' => 1])['game'];
        $this->fillRow($game['id'], 3, 0);
        $this->fillRow($game['id'], 4, 1);
        [, $state] = $this->api('POST', "/games/{$game['id']}/call", 3);
        $first = $state['claims'][0]['id'];
        [, $state] = $this->api('POST', "/games/{$game['id']}/call", 4);
        $second = $state['claims'][0]['id'];

        $this->api('POST', "/claims/$first", 2, ['action' => 'accept']);
        $results = Post::query()->where('type', 'bingo')->get()->filter(fn ($p) => $p->content['kind'] === 'results');
        $this->assertCount(1, $results);
        $this->assertSame(1, $results->first()->content['pending'], 'Sam\'s call is still waiting');

        // Dana's win is overturned after the game ended: Sam's waiting call takes 1st, in the same post.
        $this->api('POST', "/claims/$first", 2, ['action' => 'reject']);
        $this->api('POST', "/claims/$second", 2, ['action' => 'accept']);
        $results = Post::query()->where('type', 'bingo')->get()->filter(fn ($p) => $p->content['kind'] === 'results');
        $this->assertCount(1, $results, 'updated, never posted twice');
        $this->assertSame([[1, 4, 'sam']], $results->first()->content['winners']);
        $this->assertSame(0, $results->first()->content['pending']);
        $this->assertFalse($results->first()->content['open']);
    }

    #[Test]
    public function two_calls_racing_for_one_place_cannot_both_win()
    {
        $game = $this->create(['verify' => 'trust', 'places' => 1])['game'];
        $this->fillRow($game['id'], 3, 0);
        $this->fillRow($game['id'], 4, 1);
        $this->afterGrace();

        // Both requests loaded the game while it was live; the second must see the first's win under the lock.
        $stale1 = Game::query()->find($game['id']);
        $stale2 = Game::query()->find($game['id']);
        $referee = $this->app()->getContainer()->make(Referee::class);
        $a = $referee->call($stale1, Player::query()->where('user_id', 3)->first(), User::query()->find(3));
        $b = $referee->call($stale2, Player::query()->where('user_id', 4)->first(), User::query()->find(4));

        $this->assertInstanceOf(Claim::class, $a);
        $this->assertSame('bingo_over', $b);
        $this->assertSame(1, Claim::query()->count());
    }

    #[Test]
    public function a_moderator_who_plays_cannot_referee_the_game()
    {
        $game = $this->create(['verify' => 'host', 'places' => 3])['game'];
        $card = $this->fillRow($game['id'], 1, 0);
        [, $state] = $this->api('POST', "/games/{$game['id']}/call", 1);
        $this->assertFalse($state['game']['canReferee']);

        [$status, $body] = $this->api('POST', "/squares/{$card[0]}", 1, ['confirmed' => true]);
        $this->assertSame(403, $status);
        $this->assertSame('bingo_player_cannot_referee', $body['errors'][0]['code']);
        $this->assertSame(403, $this->api('POST', "/claims/{$state['claims'][0]['id']}", 1, ['action' => 'accept'])[0]);
        $this->assertSame(200, $this->api('POST', "/claims/{$state['claims'][0]['id']}", 2, ['action' => 'accept'])[0], 'the host still can');
    }

    #[Test]
    public function the_free_centre_can_be_switched_off_until_someone_has_a_card()
    {
        $game = $this->create()['game'];
        [$status, $state] = $this->api('PATCH', "/games/{$game['id']}", 2, ['freeCenter' => false]);
        $this->assertSame(200, $status);
        $this->assertFalse($state['game']['freeCenter']);

        [, $state] = $this->api('POST', "/games/{$game['id']}/join", 3);
        $this->assertNotContains(0, $state['me']['card']);

        // Locked once a card exists.
        $this->api('PATCH', "/games/{$game['id']}", 2, ['freeCenter' => true]);
        $this->assertFalse(Game::query()->find($game['id'])->free_center);
    }

    #[Test]
    public function the_pulse_remembers_missing_games()
    {
        $this->assertSame(404, $this->api('GET', '/games/999/pulse', null)[0]);
        $this->assertSame(404, $this->api('GET', '/games/999/pulse', null)[0]);

        // A new game replaces a remembered "missing".
        $game = $this->create()['game'];
        $this->assertSame(200, $this->api('GET', "/games/{$game['id']}/pulse", null)[0]);
    }

    #[Test]
    public function the_pulse_needs_the_view_permission()
    {
        // Permissions are read once per process: take it away before the first request.
        $this->database()->table('group_permission')->where('permission', 'bingo.view')->delete();
        $this->assertSame(403, $this->api('GET', '/games/1/pulse', null)[0]);
    }
}
