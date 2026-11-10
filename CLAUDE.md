# Bingo: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.** Commit subjects are conventional (`feat:`, `fix:`); the release drafter bumps from the subject.
- `js/dist` is committed and is what forums load. After any change under `js/src`, run `npm run build` in `js/` (from a fresh `npm ci`) and commit the bundle; CI fails if it is stale.
- Publishing a release is Ernest's click. Nothing is announced until he says so.

## Design rules that exist for a reason

- **The server decides every win.** Cards are dealt by `Card::deal` from the game's secret `seed` and the member's id (a local Xoshiro engine, never PHP's global RNG) and stored; `CallController` takes no line from the browser: `Referee::call` finds it on the stored marks. Never accept a card, a line or a result from the client.
- **The pool is locked once anyone has a card**: cards are dealt by position. After that only labels change (`SaveController::saveSquares`).
- **Places** come from `ranked_at` = the later of the call and the last confirmation of its squares (`Referee::rankTime`); equal moments share a place (`Referee::standings`). Filling every place ends the game (`ends_at = now`, `ended_early`). The host referees and cannot play.
- **Polling must stay cheap.** `Pulse` keeps one revision number per game in the cache; `/pulse` reads only that (no table, no visibility check: a number tells nothing). Marks never bump it. Anything every viewer must see (confirm, call, ruling, dispute, edit) calls `Pulse::bump`, which also pushes `{id, rev}` on flarum/realtime's `public` channel when Pusher is bound. The browser (`state.ts`) polls every 8 s only while live and visible, 60 s with realtime, and refetches after a random pause.
- Bit maths in `MarkController` is one guarded UPDATE (`marks + bit WHERE (marks & bit) = 0`) so double taps can't double-count the crowd. The bit is an int we computed, never input.
- **Event posts** (`BingoPost`, type `bingo`) carry everything they show in their content: no request per post. A call's post is updated in place when it is ruled on. Results are posted once (`results_posted_at` claimed with a guarded UPDATE), also lazily when a finished game is viewed (a game that simply runs out has nobody to post it).
- **Notification subjects are Users** (an API resource exists for them); the game rides in the data.
- `bingoGames` on the discussion is only on the discussion's own show endpoint; lists never pay for it.
- Flarum's translator treats a `{user}` parameter as a User model: never name a translation parameter `user`.
- LESS evaluates `min()`/`max()`: escape them (`~"min(...)"`). Cell text uses container units (`cqi` on `.BingoCard-grid`) and `--w` (the label's longest word) so whole words fit a phone's cell.
- Pages, card and forms are one lazy chunk (`play.tsx`); forum.js only wires routes, the discussion strip, the controls item, event posts and notifications.

## Testing

- `composer test:setup && composer test` (integration; SQLite and prefixed MySQL both pass), `composer analyse:phpstan`.
- `cd js && npm ci && npm run build && npm run format-check`.
- The showcase seed is `~/github/showcase/seeds/98-bingo.php`; run it locally against a forum with a small runner that requires `_seed.php`.
