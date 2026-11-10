import app from 'flarum/forum/app';
import EventPost from 'flarum/forum/components/EventPost';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import { MEDALS, lineName, placeName, t } from '../api';

/**
 * Bingo in the watch-along thread: a game starting, a call (its standing
 * is updated on the server when it is ruled on) and the results. All it
 * shows is in the post's own content: no request per post.
 */
export default class BingoPost extends EventPost {
  icon() {
    return 'fas fa-table-cells';
  }

  description(data: any): Mithril.Children {
    const c = (this.attrs as any).post.content() || {};
    const href = app.route('bingo.game', { id: String(c.game) });
    const game = (
      <Link className="BingoPost-game" href={href}>
        {c.title}
      </Link>
    );

    if (c.kind === 'call') {
      return (
        <span>
          {t('post.called', { username: data.username, line: lineName(c.line), game })}{' '}
          <span className={`BingoChip BingoChip--${c.status}`}>
            {c.status === 'accepted'
              ? c.place
                ? [MEDALS[c.place - 1], ' ', placeName(c.place)]
                : t('calls.accepted')
              : c.status === 'rejected'
                ? t('calls.rejected')
                : t('calls.pending')}
          </span>
          {Array.isArray(c.labels) && <span className="BingoPost-labels">{c.labels.join(' · ')}</span>}
        </span>
      );
    }

    if (c.kind === 'results') {
      const winners: [number, number, string][] = Array.isArray(c.winners) ? c.winners : [];
      return (
        <span>
          {t('post.results', { game })}{' '}
          {winners.length ? (
            <span className="BingoPost-winners">
              {winners.map(([place, , name]) => (
                <span className="BingoPost-winner">
                  {MEDALS[place - 1]} {name}
                </span>
              ))}
            </span>
          ) : (
            t('post.no_winner')
          )}
        </span>
      );
    }

    return (
      <span>
        {t('post.started', { username: data.username, game, count: c.squares || 0 })}{' '}
        <Link className="Button Button--primary Button--small BingoPost-play" href={href}>
          <Icon name="fas fa-table-cells" /> {t('post.play')}
        </Link>
      </span>
    );
  }
}
