import Icon from 'flarum/common/components/Icon';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { now, span, statusOf, t, when, type GameSummary } from '../api';

/** LIVE (with a pulse), "Starts in 2h 05m" or Finished. */
export function StatusPill() {
  return {
    view: ({ attrs }: { attrs: { game: GameSummary } }) => {
      const status = statusOf(attrs.game);
      return (
        <span className={classList('BingoStatus', `BingoStatus--${status}`)}>
          {status === 'live' && <span className="BingoStatus-dot" aria-hidden="true" />}
          {t(`status.${status}`)}
        </span>
      );
    },
  };
}

/** The line under a game's title: when it starts, how long is left, or when it ended. */
export function clockText(game: GameSummary, coarse = false): Mithril.Children {
  const status = statusOf(game);
  if (status === 'upcoming') return t('clock.starts_in', { time: span(Date.parse(game.startsAt) - now(), coarse) });
  if (status === 'live') return t('clock.ends_in', { time: span(Date.parse(game.endsAt) - now(), coarse) });
  return t('clock.ended', { time: when(game.endsAt) });
}

export function Clock() {
  return {
    view: ({ attrs }: { attrs: { game: GameSummary; coarse?: boolean } }) => (
      <span className="BingoClock">
        <Icon name="far fa-clock" /> {clockText(attrs.game, attrs.coarse)}
      </span>
    ),
  };
}
