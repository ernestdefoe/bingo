<?php

namespace ErnestDefoe\Bingo\Gdpr;

use ErnestDefoe\Bingo\Claim;
use ErnestDefoe\Bingo\Dispute;
use ErnestDefoe\Bingo\Game;
use ErnestDefoe\Bingo\Player;
use Flarum\Gdpr\Data\Type;

/**
 * A member's export: the games they host, their cards and marks, their
 * calls and disputes. Erasing removes their cards, calls and disputes;
 * games they host stay (other members played them) without a host.
 */
class BingoData extends Type
{
    public static function exportDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-bingo.lib.gdpr.export');
    }

    public static function anonymizeDescription(): string
    {
        return self::deleteDescription();
    }

    public static function deleteDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-bingo.lib.gdpr.delete');
    }

    public function export(): ?array
    {
        $rows = [
            'games' => Game::query()->where('user_id', $this->user->id)->get(['id', 'title', 'starts_at', 'ends_at'])->toArray(),
            'cards' => Player::query()->where('user_id', $this->user->id)->get(['game_id', 'card', 'marks', 'joined_at'])->toArray(),
            'calls' => Claim::query()->where('user_id', $this->user->id)->get(['game_id', 'line', 'status', 'place', 'created_at'])->toArray(),
            'disputes' => Dispute::query()->where('user_id', $this->user->id)->get(['claim_id', 'reason', 'created_at'])->toArray(),
        ];

        return [['bingo/bingo.json' => $this->encodeForExport($rows)]];
    }

    public function anonymize(): void
    {
        $this->delete();
    }

    public function delete(): void
    {
        Dispute::query()->where('user_id', $this->user->id)->delete();
        Claim::query()->where('user_id', $this->user->id)->delete();
        Player::query()->where('user_id', $this->user->id)->delete();
        Game::query()->where('user_id', $this->user->id)->update(['user_id' => null]);
    }
}
