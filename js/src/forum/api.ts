import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';

export type Status = 'upcoming' | 'live' | 'finished';
export type Verify = 'confirmed' | 'host' | 'trust';

export interface UserRow {
  id: number;
  username: string;
  displayName: string;
  slug: string;
  avatarUrl: string | null;
}

export interface GameSummary {
  id: number;
  slug: string;
  title: string;
  status: Status;
  startsAt: string;
  endsAt: string;
  endedEarly: boolean;
  freeCenter: boolean;
  places: number;
  verify: Verify;
  revision: number;
  players: number;
  host: number | null;
  discussion: { id: number; slug: string; title: string } | null;
  /** On lists: [place, userId], and the viewer's cells to go (null = not playing). */
  winners?: [number, number][];
  mine?: number | null;
}

export interface GameDetail extends GameSummary {
  description: string | null;
  freeLabel: string | null;
  canManage: boolean;
  locked: boolean;
}

/** [id, label, confirmed, crowd] */
export type SquareRow = [id: number, label: string, confirmed: 0 | 1, crowd: number];

export interface ClaimRow {
  id: number;
  user: number;
  line: number;
  squares: number[];
  status: 'pending' | 'accepted' | 'rejected';
  place: number | null;
  auto: boolean;
  note: string | null;
  disputes: number;
  reviewed: boolean;
  disputed: boolean;
  at: string;
}

export interface State {
  game: GameDetail;
  squares: SquareRow[];
  me: { card: number[]; marks: number } | null;
  claims: ClaimRow[];
  leaders: [userId: number, away: number][];
  near: [number, number, number];
  users: UserRow[];
  now: string;
}

export interface Hub {
  live?: GameSummary[];
  upcoming?: GameSummary[];
  past: GameSummary[];
  more: boolean;
  board?: [userId: number, wins: number, podiums: number][];
  canCreate?: boolean;
  users: UserRow[];
  now: string;
}

export const LETTERS = ['B', 'I', 'N', 'G', 'O'];
export const CENTER = 12;

/** Rows, columns, then the two diagonals: the same order as the server's Card::LINES. */
export const LINES: number[][] = [
  ...[0, 1, 2, 3, 4].map((r) => [0, 1, 2, 3, 4].map((c) => r * 5 + c)),
  ...[0, 1, 2, 3, 4].map((c) => [0, 1, 2, 3, 4].map((r) => r * 5 + c)),
  [0, 6, 12, 18, 24],
  [4, 8, 12, 16, 20],
];

export const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-bingo.forum.${key}`, params);

export function lineMask(line: number): number {
  return LINES[line].reduce((mask, cell) => mask | (1 << cell), 0);
}

export function completeLines(marks: number): number[] {
  return LINES.map((_, n) => n).filter((n) => (marks & lineMask(n)) === lineMask(n));
}

/** Cells the closest line still needs. */
export function away(marks: number): number {
  return Math.min(...LINES.map((cells) => cells.filter((cell) => !(marks & (1 << cell))).length));
}

export function lineName(line: number) {
  if (line < 5) return t('line.row', { n: line + 1 });
  if (line < 10) return t('line.column', { letter: LETTERS[line - 5] });
  return t('line.diagonal');
}

export function placeName(place: number) {
  return t(`place.${Math.min(place, 3)}`);
}

export const MEDALS = ['🥇', '🥈', '🥉'];

export function gameUrl(game: { id: number; slug: string }): string {
  return app.route('bingo.game', { id: `${game.id}-${game.slug}` });
}

/** The sentence for an error answer from our endpoints. */
export function errorText(error: any): string {
  const code = error?.response?.errors?.[0]?.code;
  const key = `ernestdefoe-bingo.forum.errors.${code}`;
  if (code && app.translator.trans(key) !== key) return extractText(app.translator.trans(key));
  if (error?.status === 0) return extractText(app.translator.trans('core.lib.error.network_message'));
  if (error?.status === 403) return extractText(app.translator.trans('core.lib.error.permission_denied_message'));
  if (error?.status === 404) return extractText(t('errors.bingo_not_found'));
  return extractText(app.translator.trans('core.lib.error.generic_message'));
}

export function call<T>(method: 'GET' | 'POST' | 'PATCH' | 'DELETE', path: string, data?: Record<string, any>, quiet = false): Promise<T> {
  const url = app.forum.attribute<string>('apiUrl') + '/bingo' + path;
  return app.request<T>({
    method,
    url: method === 'GET' && data ? url + '?' + new URLSearchParams(data).toString() : url,
    body: method === 'GET' ? undefined : data,
    background: method === 'GET',
    errorHandler: (error: any) => {
      if (!quiet) app.alerts.show({ type: 'error' }, errorText(error));
      throw error;
    },
  });
}

/** People arrive as one compact list; the forum's store lets avatar() and username() draw them the usual way. */
export function pushUsers(users: UserRow[]): void {
  for (const user of users) {
    app.store.pushObject({
      type: 'users',
      id: String(user.id),
      attributes: { username: user.username, displayName: user.displayName, slug: user.slug, avatarUrl: user.avatarUrl },
    } as any);
  }
}

export function user(id: number | null | undefined): any {
  return id ? app.store.getById('users', String(id)) : null;
}

/** "Sat, Feb 8, 6:30 PM" in the reader's language and zone. */
export function when(iso: string, withDay = true): string {
  const options: Intl.DateTimeFormatOptions = withDay
    ? { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }
    : { hour: 'numeric', minute: '2-digit' };
  try {
    return new Intl.DateTimeFormat(app.translator.getLocale() || undefined, options).format(new Date(iso));
  } catch {
    return new Date(iso).toLocaleString();
  }
}

/** "2d 4h", "1h 05m", "4:09": how long until (or since) a moment. */
export function span(ms: number, coarse = false): string {
  const s = Math.max(0, Math.floor(ms / 1000));
  const d = Math.floor(s / 86400);
  const h = Math.floor((s % 86400) / 3600);
  const mm = Math.floor((s % 3600) / 60);
  const ss = s % 60;
  if (d) return `${d}d ${h}h`;
  if (h) return `${h}h ${String(mm).padStart(2, '0')}m`;
  if (coarse) return `${Math.max(1, mm + (ss ? 1 : 0))}m`;
  return `${mm}:${String(ss).padStart(2, '0')}`;
}

/** Server time minus ours, so countdowns agree with the server's clock. */
export let skew = 0;
export function setSkew(serverNow: string): void {
  const value = Date.parse(serverNow) - Date.now();
  if (Number.isFinite(value)) skew = value;
}
export function now(): number {
  return Date.now() + skew;
}

export function statusOf(game: GameSummary): Status {
  const at = now();
  if (at < Date.parse(game.startsAt)) return 'upcoming';
  if (at < Date.parse(game.endsAt)) return 'live';
  return 'finished';
}
