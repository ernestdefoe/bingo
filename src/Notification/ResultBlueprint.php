<?php

namespace ErnestDefoe\Bingo\Notification;

use ErnestDefoe\Bingo\Claim;
use ErnestDefoe\Bingo\Game;
use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/** To the caller, when a call that waited is ruled on: "Your Bingo was confirmed: 1st place!" or turned down. */
class ResultBlueprint implements BlueprintInterface, AlertableInterface
{
    public function __construct(public Game $game, public Claim $claim, public User $player, public ?User $by = null)
    {
    }

    public function getFromUser(): ?User
    {
        return $this->by;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->player;
    }

    public function getData(): mixed
    {
        return [
            'game' => $this->game->id,
            'title' => $this->game->title,
            'claim' => $this->claim->id,
            'status' => $this->claim->status,
            'place' => $this->claim->place,
            'note' => $this->claim->note,
        ];
    }

    public static function getType(): string
    {
        return 'bingoResult';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }
}
