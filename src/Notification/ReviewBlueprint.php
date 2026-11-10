<?php

namespace ErnestDefoe\Bingo\Notification;

use ErnestDefoe\Bingo\Claim;
use ErnestDefoe\Bingo\Game;
use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * To the host: "Dana called Bingo in Super Bowl Ad Bingo" or "Sam disputed
 * Dana's Bingo". The subject is the member who did it (a User has an API
 * resource; a subject without one 500s every notification list), the game
 * rides in the data.
 */
class ReviewBlueprint implements BlueprintInterface, AlertableInterface
{
    public function __construct(public Game $game, public Claim $claim, public User $actor, public string $kind)
    {
    }

    public function getFromUser(): ?User
    {
        return $this->actor;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->actor;
    }

    public function getData(): mixed
    {
        return ['game' => $this->game->id, 'title' => $this->game->title, 'claim' => $this->claim->id, 'kind' => $this->kind];
    }

    public static function getType(): string
    {
        return 'bingoReview';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }
}
