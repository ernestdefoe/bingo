import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { MEDALS, call, placeName, statusOf, t, when, type GameDetail } from '../api';
import GameState, { useClock } from '../state';
import Card from './Card';
import { CallsPanel, LeadersPanel, Person, SquaresPanel } from './Panels';
import { Clock, StatusPill } from './parts';
import { IndexSidebar, PageStructure, openGameForm } from '../play';

declare const flarum: any;
declare const m: Mithril.Static;

/** /bingo/:id — the card, the calls, the crowd, and the host's controls. */
export default class GamePage extends Page {
  state!: GameState;
  private stop!: () => void;
  private stopClock!: () => void;
  private titled = '';

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.state = GameState.for(parseInt(m.route.param('id'), 10));
    this.stop = this.state.watch();
    this.stopClock = useClock();
    app.history.push('bingo.game', extractText(t('hub.title')));
  }

  onremove(vnode: Mithril.VnodeDOM) {
    super.onremove(vnode);
    this.stop();
    this.stopClock();
  }

  view() {
    return (
      <PageStructure className="BingoGamePage" sidebar={() => <IndexSidebar />}>
        {this.body()}
      </PageStructure>
    );
  }

  body(): Mithril.Children {
    const state = this.state;
    const data = state.data;
    if (!data) {
      return state.error ? <p className="BingoEmpty">{t('errors.bingo_not_found')}</p> : <LoadingIndicator />;
    }

    if (this.titled !== data.game.title) {
      this.titled = data.game.title;
      app.setTitle(data.game.title);
      app.setTitleCount(0);
    }

    // The host (or a moderator who isn't playing) works the squares: they go in the main column.
    const hosting = data.game.canReferee;

    return (
      <div className="BingoGame">
        {this.head(data.game)}
        {this.podium()}
        <div className="BingoGame-layout">
          <div className="BingoGame-main">
            {data.me ? <Card state={state} /> : this.join(data.game)}
            {hosting && <SquaresPanel state={state} />}
          </div>
          <div className="BingoGame-side">
            <CallsPanel state={state} />
            <LeadersPanel state={state} />
            {!hosting && <SquaresPanel state={state} />}
          </div>
        </div>
      </div>
    );
  }

  head(game: GameDetail): Mithril.Children {
    const status = statusOf(game);
    return (
      <header className="BingoGame-head">
        <div className="BingoGame-kicker">
          <StatusPill game={game} />
          <Clock game={game} />
        </div>
        <h1 className="BingoGame-title">{game.title}</h1>
        <ul className="BingoGame-meta">
          {game.host && <li>{t('game.hosted_by', { host: <Person id={game.host} /> })}</li>}
          <li>
            <Icon name="far fa-calendar" /> {when(game.startsAt)} –{' '}
            {when(game.endsAt, new Date(game.startsAt).toDateString() !== new Date(game.endsAt).toDateString())}
          </li>
          <li>
            <Icon name="fas fa-users" /> {t('game.players', { count: game.players })}
          </li>
          {game.discussion && (
            <li>
              <Link href={app.route('discussion', { id: game.discussion.slug })}>
                <Icon name="far fa-comments" /> <span>{game.discussion.title}</span>
              </Link>
            </li>
          )}
        </ul>
        {game.description && <p className="BingoGame-description">{game.description}</p>}
        <p className="BingoGame-rules">
          <Icon name="fas fa-circle-info" /> {t(`rules.${game.verify}`)} {t('rules.places', { count: game.places })}
        </p>
        {game.canManage && (
          <div className="BingoGame-manage">
            <Button className="Button" icon="fas fa-pen" onclick={() => openGameForm({ state: this.state })}>
              {t('manage.edit')}
            </Button>
            {status === 'upcoming' && (
              <Button className="Button" icon="fas fa-play" disabled={this.state.busy} onclick={() => this.state.update({ action: 'start' })}>
                {t('manage.start')}
              </Button>
            )}
            {status === 'live' && (
              <Button
                className="Button"
                icon="fas fa-flag-checkered"
                disabled={this.state.busy}
                onclick={() => confirm(extractText(t('manage.end_confirm'))) && this.state.update({ action: 'end' })}
              >
                {t('manage.end')}
              </Button>
            )}
            <Button className="Button Button--flat BingoGame-delete" icon="far fa-trash-can" onclick={() => this.remove()}>
              {t('manage.delete')}
            </Button>
          </div>
        )}
      </header>
    );
  }

  podium(): Mithril.Children {
    const data = this.state.data!;
    const winners = data.claims.filter((claim) => claim.status === 'accepted' && claim.place).sort((a, b) => a.place! - b.place! || a.id - b.id);
    if (!winners.length) return null;
    return (
      <ol className="BingoPodium" aria-label={extractText(t('game.winners'))}>
        {winners.map((claim) => (
          <li className={`BingoPodium-place BingoPodium-place--${claim.place}`}>
            <span className="BingoPodium-medal" aria-hidden="true">
              {MEDALS[claim.place! - 1]}
            </span>
            <Person id={claim.user} />
            <span className="BingoPodium-label">{placeName(claim.place!)}</span>
          </li>
        ))}
      </ol>
    );
  }

  join(game: GameDetail): Mithril.Children {
    const state = this.state;
    if (game.host === Number(app.session.user?.id())) {
      return (
        <div className="BingoJoin BingoJoin--host">
          <div className="BingoJoin-body">
            <h2>{t('join.hosting_title')}</h2>
            <p>{t('join.hosting')}</p>
          </div>
        </div>
      );
    }

    let action: Mithril.Children;
    if (statusOf(game) === 'finished') action = <p>{t('join.over')}</p>;
    else if (!app.session.user)
      action = (
        <Button
          className="Button Button--primary BingoJoin-button"
          icon="fas fa-right-to-bracket"
          onclick={() => app.modal.show(() => flarum.reg.asyncModuleImport('flarum/forum/components/LogInModal'))}
        >
          {t('join.log_in')}
        </Button>
      );
    else if (!app.forum.attribute('bingoCanPlay')) action = <p>{t('join.watch_only')}</p>;
    else
      action = (
        <Button className="Button Button--primary BingoJoin-button" icon="fas fa-table-cells" loading={state.busy} onclick={() => state.join()}>
          {t('join.button')}
        </Button>
      );

    return (
      <div className="BingoJoin">
        <div className="BingoJoin-preview" aria-hidden="true">
          {Array.from({ length: 25 }, (_, i) => (
            <span className={i === 12 ? 'is-free' : ''} />
          ))}
        </div>
        <div className="BingoJoin-body">
          <h2>{t('join.title')}</h2>
          <p>{t('join.help', { count: state.data!.squares.length })}</p>
          {action}
        </div>
      </div>
    );
  }

  remove() {
    if (!confirm(extractText(t('manage.delete_confirm')))) return;
    call('DELETE', `/games/${this.state.id}`).then(() => m.route.set(app.route('bingo')));
  }
}
