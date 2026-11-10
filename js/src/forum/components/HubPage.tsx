import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { MEDALS, call, gameUrl, pushUsers, setSkew, statusOf, t, type GameSummary, type Hub } from '../api';
import { useClock } from '../state';
import { Person } from './Panels';
import { Clock, StatusPill } from './parts';
import { IndexSidebar, PageStructure, openGameForm } from '../play';

declare const m: Mithril.Static;

/** /bingo — live, upcoming and past games, and the winners board. */
export default class HubPage extends Page {
  data: Hub | null = null;
  error = false;
  loadingMore = false;
  private stopClock!: () => void;
  private refresh: number | undefined;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    app.history.push('bingo', extractText(t('hub.title')));
    app.setTitle(extractText(t('hub.title')));
    app.setTitleCount(0);
    this.stopClock = useClock();
    this.load();
    // Player counts and new games, while the page is open and seen.
    this.refresh = window.setInterval(() => !document.hidden && this.load(), 60000);
  }

  onremove(vnode: Mithril.VnodeDOM) {
    super.onremove(vnode);
    this.stopClock();
    clearInterval(this.refresh);
  }

  load() {
    call<Hub>('GET', '', undefined, !!this.data)
      .then((data) => {
        pushUsers(data.users);
        setSkew(data.now);
        this.data = data;
      })
      .catch(() => (this.error = !this.data))
      .finally(() => m.redraw());
  }

  more() {
    const data = this.data!;
    this.loadingMore = true;
    call<Hub>('GET', '', { past: String(data.past.length) })
      .then((page) => {
        pushUsers(page.users);
        data.past.push(...page.past);
        data.more = page.more;
      })
      .catch(() => {})
      .finally(() => {
        this.loadingMore = false;
        m.redraw();
      });
  }

  view() {
    return (
      <PageStructure className="BingoHubPage" sidebar={() => <IndexSidebar />}>
        {this.body()}
      </PageStructure>
    );
  }

  body(): Mithril.Children {
    const data = this.data;
    const header = (
      <header className="BingoHub-head">
        <div>
          <h1 className="BingoHub-title">
            <Icon name="fas fa-table-cells" /> {t('hub.title')}
          </h1>
          <p className="BingoHub-lead">{t('hub.lead')}</p>
        </div>
        {app.forum.attribute('bingoCanCreate') && (
          <Button className="Button Button--primary" icon="fas fa-plus" onclick={() => openGameForm({})}>
            {t('hub.create')}
          </Button>
        )}
      </header>
    );

    if (!data) return [header, this.error ? <p className="BingoEmpty">{t('hub.load_error')}</p> : <LoadingIndicator />];

    const live = data.live || [];
    const upcoming = data.upcoming || [];
    const nothing = !live.length && !upcoming.length && !data.past.length;

    return (
      <div className="BingoHub">
        {header}
        {nothing && (
          <div className="BingoEmpty BingoEmpty--big">
            <Icon name="fas fa-table-cells" />
            <p>{t('hub.empty')}</p>
          </div>
        )}
        <div className="BingoHub-layout">
          <div className="BingoHub-lists">
            {this.section('live', live)}
            {this.section('upcoming', upcoming)}
            {this.section('past', data.past)}
            {data.more && (
              <Button className="Button BingoHub-more" loading={this.loadingMore} onclick={() => this.more()}>
                {t('hub.more')}
              </Button>
            )}
          </div>
          {!!data.board?.length && this.board(data.board)}
        </div>
      </div>
    );
  }

  section(name: 'live' | 'upcoming' | 'past', games: GameSummary[]): Mithril.Children {
    if (!games.length) return null;
    return (
      <section className={`BingoHub-section BingoHub-section--${name}`}>
        <h2 className="BingoHub-sectionTitle">{t(`hub.${name}`)}</h2>
        <ul className="BingoTiles">
          {games.map((game) => (
            <li key={game.id}>{this.tile(game)}</li>
          ))}
        </ul>
      </section>
    );
  }

  tile(game: GameSummary): Mithril.Children {
    const status = statusOf(game);
    return (
      <Link href={gameUrl(game)} className={classList('BingoTile', `BingoTile--${status}`)}>
        <span className="BingoTile-top">
          <StatusPill game={game} />
          <span className="BingoTile-players">
            <Icon name="fas fa-users" /> {game.players}
          </span>
        </span>
        <span className="BingoTile-title">{game.title}</span>
        <Clock game={game} />
        {game.discussion && (
          <span className="BingoTile-discussion">
            <Icon name="far fa-comments" /> {game.discussion.title}
          </span>
        )}
        {status !== 'finished' && game.mine !== null && game.mine !== undefined && (
          <span className={classList('BingoTile-mine', game.mine <= 1 && 'is-hot')}>
            {game.mine === 0 ? t('hub.mine_bingo') : t('hub.mine', { count: game.mine })}
          </span>
        )}
        {!!game.winners?.length && (
          <span className="BingoTile-winners">
            {game.winners.slice(0, 3).map(([place, id]) => (
              <span className="BingoTile-winner">
                {MEDALS[place - 1]} <Person id={id} />
              </span>
            ))}
          </span>
        )}
      </Link>
    );
  }

  board(board: [number, number, number][]): Mithril.Children {
    return (
      <aside className="BingoBoard">
        <h2 className="BingoHub-sectionTitle">
          <Icon name="fas fa-trophy" /> {t('hub.board')}
        </h2>
        <ol className="BingoBoard-list">
          {board.map(([id, wins, podiums], i) => (
            <li>
              <span className="BingoBoard-rank">{i + 1}</span>
              <Person id={id} />
              <span className="BingoBoard-score" title={extractText(t('hub.board_podiums', { count: podiums }))}>
                {MEDALS[0]} {wins} <span className="BingoBoard-podiums">· {podiums}</span>
              </span>
            </li>
          ))}
        </ol>
      </aside>
    );
  }
}
