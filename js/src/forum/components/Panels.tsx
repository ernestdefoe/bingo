import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import Avatar from 'flarum/common/components/Avatar';
import username from 'flarum/common/helpers/username';
import humanTime from 'flarum/common/helpers/humanTime';
import classList from 'flarum/common/utils/classList';
import Stream from 'flarum/common/utils/Stream';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { MEDALS, lineName, placeName, t, user, type ClaimRow } from '../api';
import type GameState from '../state';

function Person() {
  return {
    view: ({ attrs }: { attrs: { id: number } }) => {
      const u = user(attrs.id);
      return u ? (
        <Link className="BingoPerson" href={app.route.user(u)}>
          <Avatar user={u} className="Avatar--small" />
          <span className="BingoPerson-name">{username(u)}</span>
        </Link>
      ) : (
        <span className="BingoPerson">{username(null)}</span>
      );
    },
  };
}

export { Person };

/** "Bingo!" calls, newest first, with what was called and how it stands. */
export class CallsPanel extends Component<{ state: GameState }> {
  open = new Set<number>();

  view() {
    const data = this.attrs.state.data!;
    return (
      <section className="BingoPanel BingoCalls">
        <h3 className="BingoPanel-title">
          <Icon name="fas fa-bullhorn" /> {t('calls.title')}
        </h3>
        {data.claims.length ? (
          <ol className="BingoCalls-list">{data.claims.map((claim) => this.row(claim))}</ol>
        ) : (
          <p className="BingoPanel-empty">{t('calls.none')}</p>
        )}
      </section>
    );
  }

  row(claim: ClaimRow): Mithril.Children {
    const state = this.attrs.state;
    const data = state.data!;
    const labels = new Map(data.squares.map((row) => [row[0], row]));
    const self = claim.user === Number(app.session.user?.id());
    const manage = data.game.canManage;
    const canDispute = !self && !!data.me && !claim.disputed && !claim.reviewed && claim.status !== 'rejected';
    const open = this.open.has(claim.id);

    return (
      <li className={classList('BingoCall-row', `is-${claim.status}`, claim.disputes && !claim.reviewed && 'is-disputed')} key={claim.id}>
        <div className="BingoCall-top">
          <Person id={claim.user} />
          <span className="BingoCall-what">
            {t('calls.called', { line: lineName(claim.line) })} · {humanTime(claim.at)}
          </span>
          {this.chip(claim)}
        </div>
        <button
          type="button"
          className="BingoCall-toggle"
          aria-expanded={open ? 'true' : 'false'}
          onclick={() => (open ? this.open.delete(claim.id) : this.open.add(claim.id))}
        >
          <Icon name={open ? 'fas fa-chevron-up' : 'fas fa-chevron-down'} /> {t('calls.show_line')}
        </button>
        {open && (
          <ul className="BingoCall-squares">
            {claim.squares.map((id) => {
              const row = labels.get(id);
              return (
                <li className={row?.[2] ? 'is-confirmed' : ''}>
                  <Icon name={row?.[2] ? 'fas fa-circle-check' : 'far fa-circle'} /> {row?.[1] ?? ''}
                </li>
              );
            })}
          </ul>
        )}
        {claim.note && claim.status === 'rejected' && <p className="BingoCall-note">“{claim.note}”</p>}
        {(manage || canDispute) && (
          <div className="BingoCall-actions">
            {manage && claim.status !== 'accepted' && (
              <Button
                className="Button Button--primary Button--small"
                icon="fas fa-check"
                disabled={state.busy}
                onclick={() => state.rule(claim.id, 'accept')}
              >
                {t('calls.accept')}
              </Button>
            )}
            {manage && claim.status !== 'rejected' && (
              <Button
                className="Button Button--small"
                icon="fas fa-xmark"
                disabled={state.busy}
                onclick={() => reason(t('calls.reject_title'), t('calls.reject_help'), (note) => state.rule(claim.id, 'reject', note))}
              >
                {t('calls.reject')}
              </Button>
            )}
            {manage && claim.disputes > 0 && !claim.reviewed && claim.status !== 'rejected' && (
              <Button
                className="Button Button--small"
                icon="fas fa-scale-balanced"
                disabled={state.busy}
                onclick={() => state.rule(claim.id, 'uphold')}
              >
                {t('calls.uphold')}
              </Button>
            )}
            {canDispute && !manage && (
              <Button
                className="Button Button--small Button--flat"
                icon="fas fa-flag"
                disabled={state.busy}
                onclick={() => reason(t('calls.dispute_title'), t('calls.dispute_help'), (note) => state.dispute(claim.id, note))}
              >
                {t('calls.dispute')}
              </Button>
            )}
          </div>
        )}
      </li>
    );
  }

  chip(claim: ClaimRow): Mithril.Children {
    const disputed = claim.disputes > 0 && !claim.reviewed && claim.status !== 'rejected';
    let text: Mithril.Children;
    if (claim.status === 'accepted') text = claim.place ? [MEDALS[claim.place - 1] + ' ', placeName(claim.place)] : t('calls.accepted');
    else if (claim.status === 'rejected') text = t('calls.rejected');
    else text = t('calls.pending');

    return (
      <span className="BingoCall-chips">
        <span className={`BingoChip BingoChip--${claim.status}`}>{text}</span>
        {disputed && <span className="BingoChip BingoChip--disputed">{t('calls.disputed', { count: claim.disputes })}</span>}
      </span>
    );
  }
}

/** Who is closest to Bingo, and how many players are one away. */
export class LeadersPanel extends Component<{ state: GameState }> {
  view() {
    const data = this.attrs.state.data!;
    if (!data.leaders.length) return null;
    const [one] = data.near;
    return (
      <section className="BingoPanel BingoLeaders">
        <h3 className="BingoPanel-title">
          <Icon name="fas fa-fire" /> {t('leaders.title')}
        </h3>
        {one > 1 && <p className="BingoLeaders-hot">{t('leaders.one_away', { count: one })}</p>}
        <ol className="BingoLeaders-list">
          {data.leaders.map(([id, n]) => (
            <li>
              <Person id={id} />
              <span className={classList('BingoAway', n === 1 && 'is-hot')}>{n === 1 ? t('leaders.away_one') : t('leaders.away', { count: n })}</span>
            </li>
          ))}
        </ol>
      </section>
    );
  }
}

/**
 * Every square in the pool with how many players marked it ("the crowd"),
 * most marked first. The host confirms squares here as they happen.
 */
export class SquaresPanel extends Component<{ state: GameState }> {
  filter = Stream('');

  view() {
    const state = this.attrs.state;
    const data = state.data!;
    const manage = data.game.canManage && state.status !== 'upcoming';
    const players = Math.max(1, data.game.players);
    const q = this.filter().trim().toLowerCase();
    // Most marked first: the crowd's hint at what happened. The host sees what's left to confirm on top.
    const rows = data.squares
      .filter((row) => !q || row[1].toLowerCase().includes(q))
      .sort((a, b) => (manage ? a[2] - b[2] : 0) || b[3] - a[3] || a[0] - b[0]);
    const confirmed = data.squares.filter((row) => row[2]).length;

    return (
      <section className="BingoPanel BingoSquares">
        <h3 className="BingoPanel-title">
          <Icon name="fas fa-table-cells" /> {t('squares.title')}
          <span className="BingoPanel-count">{t('squares.confirmed_count', { count: confirmed, total: data.squares.length })}</span>
        </h3>
        {manage && <p className="helpText">{t(`squares.help_${data.game.verify}`)}</p>}
        {data.squares.length > 12 && (
          <input
            className="FormControl BingoSquares-filter"
            type="search"
            placeholder={extractText(t('squares.filter'))}
            aria-label={extractText(t('squares.filter'))}
            value={this.filter()}
            oninput={(e: InputEvent) => this.filter((e.target as HTMLInputElement).value)}
          />
        )}
        <ul className="BingoSquares-list">
          {rows.map(([id, label, done, crowd]) => (
            <li className={classList('BingoSquare', done && 'is-confirmed')} key={id}>
              <span className="BingoSquare-bar" style={{ width: `${Math.round((crowd / players) * 100)}%` }} aria-hidden="true" />
              <span className="BingoSquare-label">
                {!!done && <Icon name="fas fa-circle-check" />} {label}
              </span>
              <span className="BingoSquare-crowd" title={extractText(t('squares.crowd', { count: crowd }))}>
                <Icon name="fas fa-user-check" /> {crowd}
              </span>
              {manage && (
                <Button
                  className={classList('Button Button--small', done ? 'Button--flat' : 'Button--primary')}
                  disabled={state.busy}
                  onclick={() => state.confirm(id, !done)}
                >
                  {done ? t('squares.undo') : t('squares.happened')}
                </Button>
              )}
            </li>
          ))}
        </ul>
      </section>
    );
  }
}

/** A short reason before a dispute or a refusal. */
export function reason(title: Mithril.Children, help: Mithril.Children, onsubmit: (text: string) => Promise<unknown>): void {
  app.modal.show(() => import('./ReasonModal'), { title, help, onsubmit });
}
