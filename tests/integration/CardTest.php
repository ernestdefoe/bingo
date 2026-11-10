<?php

namespace ErnestDefoe\Bingo\Tests\integration;

use ErnestDefoe\Bingo\Card;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CardTest extends TestCase
{
    #[Test]
    public function a_deal_is_fixed_per_member_and_differs_between_members()
    {
        $pool = range(101, 140);

        $card = Card::deal('seed', 7, $pool, true);
        $this->assertSame($card, Card::deal('seed', 7, $pool, true));
        $this->assertNotSame($card, Card::deal('seed', 8, $pool, true));
        $this->assertNotSame($card, Card::deal('other', 7, $pool, true));

        $this->assertCount(25, $card);
        $this->assertSame(0, $card[Card::CENTER]);
        $squares = array_values(array_filter($card));
        $this->assertCount(24, array_unique($squares));
        $this->assertEmpty(array_diff($squares, $pool));

        $full = Card::deal('seed', 7, $pool, false);
        $this->assertNotContains(0, $full);
        $this->assertCount(25, array_unique($full));
    }

    #[Test]
    public function lines_and_distance_come_from_the_marks()
    {
        $marks = Card::initialMarks(true);
        $this->assertSame([], Card::completeLines($marks));
        $this->assertSame(4, Card::away($marks));

        foreach ([10, 11, 13] as $cell) {
            $marks |= Card::bit($cell);
        }
        $this->assertSame(1, Card::away($marks));

        $marks |= Card::bit(14);
        $this->assertSame([2], Card::completeLines($marks));
        $this->assertSame(0, Card::away($marks));

        $diagonal = array_sum(array_map(fn ($c) => Card::bit($c), [0, 6, 12, 18, 24]));
        $this->assertSame([10], Card::completeLines($diagonal));
    }
}
