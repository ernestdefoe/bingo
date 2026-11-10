<?php

namespace ErnestDefoe\Bingo;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * A 5×5 card: 25 cells, row by row, holding square ids (0 = the free
 * centre). Marks are a 25-bit mask, bit n = cell n.
 *
 * The deal is seeded by the game's secret seed and the member's id, so a
 * card is the same every time it is dealt and nobody (not the player, not
 * the browser) can pick theirs. Xoshiro is a local engine: dealing never
 * touches PHP's global random state.
 */
final class Card
{
    public const SIZE = 25;
    public const CENTER = 12;
    public const ALL = (1 << self::SIZE) - 1;

    /** Rows, columns, then the two diagonals. */
    public const LINES = [
        [0, 1, 2, 3, 4], [5, 6, 7, 8, 9], [10, 11, 12, 13, 14], [15, 16, 17, 18, 19], [20, 21, 22, 23, 24],
        [0, 5, 10, 15, 20], [1, 6, 11, 16, 21], [2, 7, 12, 17, 22], [3, 8, 13, 18, 23], [4, 9, 14, 19, 24],
        [0, 6, 12, 18, 24], [4, 8, 12, 16, 20],
    ];

    /**
     * @param list<int> $pool square ids in position order
     * @return list<int>
     */
    public static function deal(string $seed, int $userId, array $pool, bool $freeCenter): array
    {
        $randomizer = new Randomizer(new Xoshiro256StarStar(hash('sha256', $seed.':'.$userId, true)));
        $cells = array_slice($randomizer->shuffleArray($pool), 0, $freeCenter ? 24 : 25);
        if ($freeCenter) {
            array_splice($cells, self::CENTER, 0, [0]);
        }

        return array_values(array_map('intval', $cells));
    }

    public static function bit(int $cell): int
    {
        return 1 << $cell;
    }

    /** The marks a new card starts with: the free centre is always marked. */
    public static function initialMarks(bool $freeCenter): int
    {
        return $freeCenter ? self::bit(self::CENTER) : 0;
    }

    /** @return list<int> line numbers whose five cells are all marked */
    public static function completeLines(int $marks): array
    {
        $lines = [];
        foreach (self::LINES as $n => $cells) {
            $mask = self::mask($cells);
            if (($marks & $mask) === $mask) {
                $lines[] = $n;
            }
        }

        return $lines;
    }

    /** How many more marks the closest line needs (0 = Bingo). */
    public static function away(int $marks): int
    {
        $best = 5;
        foreach (self::LINES as $cells) {
            $best = min($best, 5 - substr_count(decbin($marks & self::mask($cells)), '1'));
        }

        return $best;
    }

    /**
     * @param list<int> $card
     * @return list<int> the line's square ids, without the free centre
     */
    public static function lineSquares(array $card, int $line): array
    {
        return array_values(array_filter(array_map(fn ($cell) => $card[$cell] ?? 0, self::LINES[$line])));
    }

    /** @param list<int> $cells */
    private static function mask(array $cells): int
    {
        $mask = 0;
        foreach ($cells as $cell) {
            $mask |= 1 << $cell;
        }

        return $mask;
    }

    /** @param list<int> $card */
    public static function encode(array $card): string
    {
        return implode(',', $card);
    }

    /** @return list<int> */
    public static function decode(string $card): array
    {
        return array_map('intval', explode(',', $card));
    }
}
