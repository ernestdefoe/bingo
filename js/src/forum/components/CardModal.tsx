import app from 'flarum/forum/app';
import Modal, { type IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import { gameUrl, lineName, placeName, statusOf, t, MEDALS } from '../api';
import GameState, { useClock } from '../state';
import Card from './Card';
import { Person } from './Panels';
import { Clock, StatusPill } from './parts';

declare const flarum: any;

/**
 * The card over a watch-along discussion: mark it without leaving the
 * thread. Full-screen on a phone (Flarum's modals are), so cells stay big.
 */
export default class CardModal extends Modal<IInternalModalAttrs & { id: number }> {
  state!: GameState;
  private stop!: () => void;
  private stopClock!: () => void;

  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);
    this.state = GameState.for(this.attrs.id);
    this.stop = this.state.watch();
    this.stopClock = useClock();
  }

  onremove(vnode: Mithril.VnodeDOM<any, this>) {
    super.onremove(vnode);
    this.stop();
    this.stopClock();
  }

  className() {
    return 'BingoCardModal';
  }

  title() {
    return this.state.data?.game.title ?? t('hub.title');
  }

  content() {
    const state = this.state;
    const data = state.data;
    if (!data) return <div className="Modal-body">{state.error ? <p>{t('errors.bingo_not_found')}</p> : <LoadingIndicator />}</div>;

    const game = data.game;
    const recent = data.claims.slice(0, 3);
    return (
      <div className="Modal-body">
        <div className="BingoCardModal-kicker">
          <StatusPill game={game} />
          <Clock game={game} />
          <span className="BingoCardModal-players">
            <Icon name="fas fa-users" /> {t('game.players', { count: game.players })}
          </span>
        </div>
        {data.me ? <Card state={state} /> : this.join()}
        {!!recent.length && (
          <ul className="BingoCardModal-calls">
            {recent.map((claim) => (
              <li>
                <Person id={claim.user} /> {t('calls.called', { line: lineName(claim.line) })}{' '}
                {claim.status === 'accepted' && claim.place ? `${MEDALS[claim.place - 1]} ${placeName(claim.place)}` : null}
              </li>
            ))}
          </ul>
        )}
        <p className="BingoCardModal-more">
          <Link href={gameUrl(game)} onclick={() => this.hide()}>
            {t('card.open_page')} <Icon name="fas fa-arrow-right" />
          </Link>
        </p>
      </div>
    );
  }

  join(): Mithril.Children {
    const state = this.state;
    if (statusOf(state.data!.game) === 'finished') return <p className="BingoCardModal-note">{t('join.over')}</p>;
    if (!app.session.user) {
      return (
        <Button
          className="Button Button--primary Button--block"
          icon="fas fa-right-to-bracket"
          onclick={() => app.modal.show(() => flarum.reg.asyncModuleImport('flarum/forum/components/LogInModal'))}
        >
          {t('join.log_in')}
        </Button>
      );
    }
    if (!app.forum.attribute('bingoCanPlay')) return <p className="BingoCardModal-note">{t('join.watch_only')}</p>;
    return (
      <div className="BingoCardModal-join">
        <p>{t('join.help', { count: state.data!.squares.length })}</p>
        <Button className="Button Button--primary Button--block" icon="fas fa-table-cells" loading={state.busy} onclick={() => state.join()}>
          {t('join.button')}
        </Button>
      </div>
    );
  }
}
