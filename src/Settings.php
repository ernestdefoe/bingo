<?php

namespace ErnestDefoe\Bingo;

final class Settings
{
    public const PREFIX = 'ernestdefoe-bingo.';

    public const NAV = self::PREFIX.'nav';
    public const INDEX = self::PREFIX.'allow_indexing';

    public const VIEW = 'bingo.view';
    public const PLAY = 'bingo.play';
    public const CREATE = 'bingo.create';
    public const MODERATE = 'bingo.moderate';

    /** How calls are checked. */
    public const VERIFY_CONFIRMED = 'confirmed'; // accepted the moment every square on the line is confirmed
    public const VERIFY_HOST = 'host';           // the host or a moderator accepts each call
    public const VERIFY_TRUST = 'trust';         // honor system: calls count at once, anyone can dispute

    public const VERIFY_MODES = [self::VERIFY_CONFIRMED, self::VERIFY_HOST, self::VERIFY_TRUST];
}
