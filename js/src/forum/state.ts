import app from 'flarum/forum/app';
import type Mithril from 'mithril';
import { call, completeLines, now, pushUsers, setSkew, statusOf, type State } from './api';

declare const m: Mithril.Static;

/*
 * One GameState per game, shared by everything showing it (the game page,
 * the card in a discussion), and running only while something shows it.
 *
 * Freshness costs almost nothing: when flarum/realtime is connected the
 * server pushes the game's revision; otherwise the state polls the pulse,
 * a cached number, every few seconds while the game is live and the tab is
 * visible. Only a changed number fetches the game, after a random pause so
 * a crowd doesn't arrive in the same second. Your own taps never cause
 * anyone else a fetch.
 */

const POLL = 8000; // live, no realtime
const SLOW = 60000; // with realtime, and the crowd numbers
const games = new Map<number, GameState>();

export default class GameState {
  data: State | null = null;
  error = false;
  busy = false;
  private refs = 0;
  private timer: number | undefined;
  private lastFull = 0;
  private channel: any = null;
  private onPush = (data: any) => {
    if (Number(data?.id) === this.id && Number(data?.rev) !== this.data?.game.revision) this.soon();
  };
  private onVisible = () => {
    if (!document.hidden) this.tick();
  };

  static for(id: number): GameState {
    let state = games.get(id);
    if (!state) games.set(id, (state = new GameState(id)));
    return state;
  }

  constructor(public id: number) {}

  /** Start watching; the returned function stops. */
  watch(): () => void {
    if (this.refs++ === 0) {
      document.addEventListener('visibilitychange', this.onVisible);
      this.load();
    }
    return () => {
      if (--this.refs > 0) return;
      clearTimeout(this.timer);
      document.removeEventListener('visibilitychange', this.onVisible);
      this.channel?.unbind?.('bingo', this.onPush);
      this.channel = null;
    };
  }

  load(): Promise<void> {
    return call<State>('GET', `/games/${this.id}`, undefined, true)
      .then((state) => this.apply(state))
      .catch(() => {
        this.error = !this.data;
        this.schedule();
        m.redraw();
      });
  }

  apply(state: State): void {
    pushUsers(state.users);
    setSkew(state.now);
    this.data = state;
    this.error = false;
    this.lastFull = Date.now();
    this.schedule();
    m.redraw();
  }

  get status() {
    return this.data ? statusOf(this.data.game) : null;
  }

  /** The member's line that can be called right now, if any. */
  get canCall(): boolean {
    const data = this.data;
    if (!data?.me || this.status !== 'live') return false;
    const mine = data.claims.filter((claim) => claim.user === Number(app.session.user?.id()));
    if (mine.some((claim) => claim.status !== 'rejected')) return false;
    const refused = mine.map((claim) => claim.line);
    return completeLines(data.me.marks).some((line) => !refused.includes(line));
  }

  // ── actions ──────────────────────────────────────────────────────

  /** Optimistic: the dab appears at once and is taken back if the server says no. */
  mark(cell: number, on: boolean): void {
    const me = this.data?.me;
    if (!me) return;
    const before = me.marks;
    me.marks = on ? me.marks | (1 << cell) : me.marks & ~(1 << cell);
    call<{ marks: number }>('POST', `/games/${this.id}/mark`, { cell, on })
      .then((res) => {
        if (this.data?.me) this.data.me.marks = res.marks;
      })
      .catch(() => {
        if (this.data?.me) this.data.me.marks = before;
      })
      .finally(() => m.redraw());
  }

  join() {
    return this.act('POST', `/games/${this.id}/join`);
  }
  call() {
    return this.act('POST', `/games/${this.id}/call`);
  }
  rule(claim: number, action: 'accept' | 'reject' | 'uphold', note?: string) {
    return this.act('POST', `/claims/${claim}`, { action, note });
  }
  dispute(claim: number, reason?: string) {
    return this.act('POST', `/claims/${claim}/dispute`, { reason });
  }
  confirm(square: number, confirmed: boolean) {
    return this.act('POST', `/squares/${square}`, { confirmed });
  }
  update(data: Record<string, any>) {
    return this.act('PATCH', `/games/${this.id}`, data);
  }

  private act(method: 'POST' | 'PATCH', path: string, data: Record<string, any> = {}): Promise<State> {
    this.busy = true;
    m.redraw();
    return call<State>(method, path, data)
      .then((state) => {
        this.apply(state);
        return state;
      })
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }

  // ── freshness ────────────────────────────────────────────────────

  private realtime(): boolean {
    const socket = (app as any).websocket;
    if (!socket || typeof socket.subscribe !== 'function') return false;
    // Realtime subscribes only guests to "public"; members join it themselves (no auth needed).
    const channel = socket.subscribe('public');
    if (channel && channel !== this.channel) {
      this.channel?.unbind?.('bingo', this.onPush);
      channel.bind('bingo', this.onPush);
      this.channel = channel;
    }
    return socket.connection?.state === 'connected';
  }

  private schedule(): void {
    clearTimeout(this.timer);
    if (!this.refs || !this.data) return;
    const game = this.data.game;
    const status = statusOf(game);
    if (status === 'finished') return;

    // Upcoming: wake when it starts. Live: poll, slower with realtime; reload when it ends.
    const pushed = this.realtime();
    const until = Date.parse(status === 'upcoming' ? game.startsAt : game.endsAt) - now();
    const wait = status === 'upcoming' ? Math.min(until + 500, pushed ? SLOW : SLOW * 5) : Math.min(pushed ? SLOW : POLL, until + 500);
    this.timer = window.setTimeout(() => this.tick(), Math.max(1000, Math.min(wait, 2 ** 31 - 1)));
  }

  private tick(): void {
    if (!this.refs || !this.data) return;
    if (document.hidden) return this.schedule();
    const game = this.data.game;
    const crossed = statusOf(game) !== this.data.game.status;
    if (crossed || Date.now() - this.lastFull > SLOW) {
      this.load();
      return;
    }
    call<{ rev: number; now: string }>('GET', `/games/${this.id}/pulse`, undefined, true)
      .then((pulse) => {
        setSkew(pulse.now);
        if (pulse.rev !== this.data?.game.revision) this.soon();
        else this.schedule();
      })
      .catch((error) => error?.status !== 404 && this.schedule());
  }

  /** Fetch after a random pause of up to 1.5 s, so everyone watching doesn't arrive at once. */
  private soon(): void {
    clearTimeout(this.timer);
    this.timer = window.setTimeout(() => this.load(), Math.random() * 1500);
  }
}

/** Redraws once a second while a countdown is on screen. */
let tickers = 0;
let clock: number | undefined;
export function useClock(): () => void {
  if (tickers++ === 0) clock = window.setInterval(() => !document.hidden && m.redraw(), 1000);
  return () => {
    if (--tickers === 0) clearInterval(clock);
  };
}
