import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import type Discussion from 'flarum/common/models/Discussion';
import type Mithril from 'mithril';
import { gameUrl, statusOf, t, type GameSummary } from '../api';
import { Clock, StatusPill } from './parts';

declare const m: Mithril.Static;

/**
 * The games of a watch-along discussion, under its title: what, when, how
 * many play, and the card one tap away. It reads the discussion's own
 * payload (no request) and redraws every 30 s for the clock.
 */
export default class DiscussionBingo extends Component<{ discussion: Discussion }> {
  private timer: number | undefined;

  oncreate(vnode: Mithril.VnodeDOM<{ discussion: Discussion }, this>) {
    super.oncreate(vnode);
    this.timer = window.setInterval(() => !document.hidden && m.redraw(), 30000);
  }

  onremove(vnode: Mithril.VnodeDOM<{ discussion: Discussion }, this>) {
    super.onremove(vnode);
    clearInterval(this.timer);
  }

  view() {
    const games = (this.attrs.discussion.attribute<GameSummary[]>('bingoGames') || []).slice(0, 3);
    return <div className="BingoStrips">{games.map((game) => this.strip(game))}</div>;
  }

  strip(game: GameSummary): Mithril.Children {
    const status = statusOf(game);
    return (
      <div className={`BingoStrip BingoStrip--${status}`} key={game.id}>
        <span className="BingoStrip-icon" aria-hidden="true">
          <Icon name="fas fa-table-cells" />
        </span>
        <span className="BingoStrip-text">
          <Link className="BingoStrip-title" href={gameUrl(game)}>
            {game.title}
          </Link>
          <span className="BingoStrip-meta">
            <StatusPill game={game} />
            <Clock game={game} coarse />
            <span>
              <Icon name="fas fa-users" /> {game.players}
            </span>
          </span>
        </span>
        {status !== 'finished' ? (
          <Button
            className="Button Button--primary BingoStrip-play"
            icon="fas fa-table-cells"
            onclick={() => app.modal.show(() => import('../play').then((play) => ({ default: play.CardModal })), { id: game.id })}
          >
            {t('strip.card')}
          </Button>
        ) : (
          <Link className="Button BingoStrip-play" href={gameUrl(game)}>
            {t('strip.results')}
          </Link>
        )}
      </div>
    );
  }
}
