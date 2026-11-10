<?php

namespace ErnestDefoe\Bingo\Post;

use Carbon\Carbon;
use Flarum\Post\AbstractEventPost;

/**
 * A line in the watch-along discussion: a game starting ("game"), a call
 * ("call", updated in place when it is ruled on) and the final results
 * ("results"). The content carries everything it shows, so the post
 * renders without asking the server about the game.
 */
class BingoPost extends AbstractEventPost
{
    public static string $type = 'bingo';

    /** @param array<string, mixed> $content */
    public static function reply(int $discussionId, ?int $userId, array $content): static
    {
        $post = new static();
        $post->content = $content;
        $post->created_at = Carbon::now();
        $post->discussion_id = $discussionId;
        $post->user_id = $userId;

        return $post;
    }
}
