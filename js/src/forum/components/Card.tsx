import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { LETTERS, LINES, away, completeLines, lineName, t, type SquareRow } from '../api';
import type GameState from '../state';
import { confetti } from '../confetti';

/**
 * The member's 5×5 card. Every cell is a real button (keyboard, screen
 * readers, aria-pressed) at least 60px on a 390px phone. A tap dabs or
 * wipes a cell at once; the server stores it. Squares the host has
 * confirmed carry a check, finished lines glow, and the Bingo button wakes
 * up when a line can be called. The server decides what the call is worth.
 */
export default class Card extends Component<{ state: GameState }> {
  view() {
    const state = this.attrs.state;
    const data = state.data!;
    const me = data.me!;
    const squares = new Map<number, SquareRow>(data.squares.map((row) => [row[0], row]));
    const live = state.status === 'live';
    const lines = completeLines(me.marks);
    const lit = new Set(lines.flatMap((line) => LINES[line]));
    const free = data.game.freeLabel || extractText(t('card.free'));

    return (
      <div className={classList('BingoCard', !live && 'is-idle', lines.length && 'has-line')}>
        <div className="BingoCard-head" aria-hidden="true">
          {LETTERS.map((letter) => (
            <span>{letter}</span>
          ))}
        </div>
        <div className="BingoCard-grid" role="group" aria-label={extractText(t('card.label'))}>
          {me.card.map((id, cell) => {
            const marked = !!(me.marks & (1 << cell));
            if (id === 0) {
              return (
                <div className="BingoCell is-free is-marked" key={cell}>
                  <Icon name="fas fa-star" className="BingoCell-star" />
                  <span className="BingoCell-label">{free}</span>
                </div>
              );
            }
            const square = squares.get(id);
            const label = square?.[1] ?? '';
            const word = Math.max(...label.split(/\s+/).map((w) => w.length));
            return (
              <button
                key={cell}
                type="button"
                className={classList(
                  'BingoCell',
                  marked && 'is-marked',
                  square?.[2] && 'is-confirmed',
                  lit.has(cell) && 'is-line',
                  label.length > 40 ? 'is-long' : label.length > 18 && 'is-mid'
                )}
                style={{ '--w': Math.max(word, 6) }}
                aria-pressed={marked ? 'true' : 'false'}
                title={label}
                disabled={!live}
                onclick={() => state.mark(cell, !marked)}
              >
                <span className="BingoCell-dab" aria-hidden="true" />
                <span className="BingoCell-label">{label}</span>
                {!!square?.[2] && <Icon name="fas fa-check" className="BingoCell-confirmed" />}
              </button>
            );
          })}
        </div>
        {this.footer(lines)}
      </div>
    );
  }

  footer(lines: number[]): Mithril.Children {
    const state = this.attrs.state;
    const data = state.data!;
    const status = state.status;
    const mine = data.claims.filter((claim) => claim.user === Number(app.session.user?.id()));
    const won = mine.find((claim) => claim.status === 'accepted');
    const waiting = mine.find((claim) => claim.status === 'pending');
    const togo = away(data.me!.marks);

    let note: Mithril.Children;
    if (won) note = won.place ? t('card.won', { place: t(`place.${won.place}`) }) : t('card.won_unplaced');
    else if (waiting) note = t('card.waiting');
    else if (status === 'upcoming') note = t('card.not_started');
    else if (status === 'finished') note = t('card.over');
    else if (state.canCall) note = t('card.call_it', { line: lineName(lines[0]) });
    else if (lines.length) note = t('card.refused');
    else note = togo === 1 ? t('card.one_away') : t('card.away', { count: togo });

    return (
      <div className="BingoCard-foot">
        <p className="BingoCard-note" aria-live="polite">
          {note}
        </p>
        {status === 'live' && !won && (
          <Button
            className={classList('Button Button--primary BingoCall', state.canCall && 'is-ready')}
            disabled={!state.canCall || state.busy}
            loading={state.busy}
            onclick={(e: MouseEvent) => {
              const from = e.currentTarget as HTMLElement;
              state.call().then((data) => {
                const me = Number(app.session.user?.id());
                if (data.claims.some((claim) => claim.user === me && claim.status === 'accepted')) confetti(from);
              });
            }}
          >
            {t('card.bingo')}
          </Button>
        )}
      </div>
    );
  }
}
