import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
import Select from 'flarum/common/components/Select';
import Stream from 'flarum/common/utils/Stream';
import extractText from 'flarum/common/utils/extractText';
import type Discussion from 'flarum/common/models/Discussion';
import type Mithril from 'mithril';
import { call, gameUrl, statusOf, t, type State } from '../api';
import type GameState from '../state';

declare const m: Mithril.Static;

const TEMPLATES = ['super_bowl', 'football', 'keynote', 'awards', 'stream'];
const MIN = 24;

/** "2026-02-08T18:30" for a datetime-local input, in the reader's zone. */
function local(date: Date): string {
  return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
}

interface Attrs extends IFormModalAttrs {
  discussion?: Discussion;
  state?: GameState;
}

/**
 * Start a game, or edit one. Squares are one per line, so a pool can be
 * pasted; ready-made packs fill it for the usual events. Once anyone has a
 * card the pool is locked to its count and order (cards are dealt by
 * position), but wording can still be fixed.
 */
export default class GameModal extends FormModal<Attrs> {
  title_ = Stream('');
  description = Stream('');
  squares = Stream('');
  freeCenter = Stream(true);
  freeLabel = Stream('');
  startsAt = Stream('');
  endsAt = Stream('');
  places = Stream('1');
  verify = Stream('confirmed');
  ids: number[] = [];

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    const game = this.attrs.state?.data?.game;
    if (game) {
      const data = this.attrs.state!.data!;
      this.title_(game.title);
      this.description(game.description || '');
      this.squares(data.squares.map((row) => row[1]).join('\n'));
      this.ids = data.squares.map((row) => row[0]);
      this.freeCenter(game.freeCenter);
      this.freeLabel(game.freeLabel || '');
      this.startsAt(local(new Date(game.startsAt)));
      this.endsAt(local(new Date(game.endsAt)));
      this.places(String(game.places));
      this.verify(game.verify);
    } else {
      const start = new Date(Math.ceil(Date.now() / 3600000) * 3600000);
      this.startsAt(local(start));
      this.endsAt(local(new Date(start.getTime() + 3 * 3600000)));
      if (this.attrs.discussion) this.title_(this.attrs.discussion.title());
    }
  }

  get editing() {
    return !!this.attrs.state?.data;
  }

  get locked() {
    return !!this.attrs.state?.data?.game.locked;
  }

  className() {
    return 'BingoGameModal';
  }

  title() {
    return this.editing ? t('form.edit_title') : t('form.title');
  }

  lines(): string[] {
    return this.squares()
      .split('\n')
      .map((line) => line.trim())
      .filter(Boolean);
  }

  content() {
    const count = this.lines().length;
    const need = MIN + (this.freeCenter() ? 0 : 1);
    const started = this.editing && statusOf(this.attrs.state!.data!.game) !== 'upcoming';

    return (
      <div className="Modal-body">
        <div className="Form">
          {this.attrs.discussion && <p className="helpText BingoGameModal-where">{t('form.in_discussion', { title: this.attrs.discussion.title() })}</p>}
          <div className="Form-group">
            <label for="bingo-title">{t('form.name')}</label>
            <input id="bingo-title" className="FormControl" maxlength={100} bidi={this.title_} required placeholder={extractText(t('form.name_placeholder'))} />
          </div>
          <div className="Form-group">
            <label for="bingo-description">{t('form.description')}</label>
            <textarea id="bingo-description" className="FormControl" rows={2} maxlength={1000} bidi={this.description} />
          </div>
          <div className="Form-group">
            <label for="bingo-squares">{t('form.squares')}</label>
            <p className="helpText">{this.locked ? t('form.squares_locked') : t('form.squares_help', { count: need })}</p>
            {!this.locked && (
              <div className="BingoGameModal-templates">
                <Select
                  value=""
                  options={{
                    '': extractText(t('form.template')),
                    ...Object.fromEntries(TEMPLATES.map((key) => [key, extractText(t(`templates.${key}.name`))])),
                  }}
                  onchange={(key: string) => key && this.fill(key)}
                />
              </div>
            )}
            <textarea id="bingo-squares" className="FormControl BingoGameModal-squares" rows={10} bidi={this.squares} />
            <p className={'BingoGameModal-count' + (count < need ? ' is-short' : '')}>{t('form.count', { count, need })}</p>
          </div>
          <div className="Form-group BingoGameModal-row">
            <Switch state={this.freeCenter()} disabled={this.locked} onchange={(value: boolean) => this.freeCenter(value)}>
              {t('form.free_center')}
            </Switch>
            {this.freeCenter() && (
              <input
                className="FormControl BingoGameModal-free"
                maxlength={30}
                bidi={this.freeLabel}
                placeholder={extractText(t('card.free'))}
                aria-label={extractText(t('form.free_label'))}
              />
            )}
          </div>
          <div className="Form-group BingoGameModal-times">
            <label>
              {t('form.starts')}
              <input className="FormControl" type="datetime-local" bidi={this.startsAt} disabled={started} required />
            </label>
            <label>
              {t('form.ends')}
              <input className="FormControl" type="datetime-local" bidi={this.endsAt} min={this.startsAt()} required />
            </label>
          </div>
          <div className="Form-group BingoGameModal-times">
            <label>
              {t('form.places')}
              <Select
                value={this.places()}
                options={{ 1: extractText(t('form.places_1')), 2: extractText(t('form.places_2')), 3: extractText(t('form.places_3')) }}
                onchange={this.places}
              />
            </label>
            <label>
              {t('form.verify')}
              <Select
                value={this.verify()}
                options={{
                  confirmed: extractText(t('form.verify_confirmed')),
                  host: extractText(t('form.verify_host')),
                  trust: extractText(t('form.verify_trust')),
                }}
                onchange={this.verify}
              />
            </label>
          </div>
          <p className="helpText">{t(`rules.${this.verify()}`)}</p>
          <div className="Form-group">
            <Button className="Button Button--primary Button--block" type="submit" loading={this.loading} disabled={count < need || !this.title_().trim()}>
              {this.editing ? t('form.save') : t('form.create')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  fill(key: string) {
    const pack = extractText(t(`templates.${key}.squares`));
    const current = this.lines();
    const add = pack
      .split('\n')
      .map((line) => line.trim())
      .filter((line) => line && !current.some((c) => c.toLowerCase() === line.toLowerCase()));
    this.squares([...current, ...add].join('\n'));
    if (!this.title_().trim()) this.title_(extractText(t(`templates.${key}.name`)));
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    const lines = this.lines();
    const iso = (value: string) => (value ? new Date(value).toISOString() : null);
    const body: Record<string, any> = {
      title: this.title_(),
      description: this.description(),
      freeLabel: this.freeLabel(),
      places: Number(this.places()),
      verify: this.verify(),
      endsAt: iso(this.endsAt()),
    };

    if (this.locked) {
      if (lines.length !== this.ids.length) {
        app.alerts.show({ type: 'error' }, t('form.locked_count', { count: this.ids.length }));
        return;
      }
      body.squares = lines.map((label, i) => ({ id: this.ids[i], label }));
    } else {
      body.squares = lines;
      body.freeCenter = this.freeCenter();
    }
    if (!this.editing || statusOf(this.attrs.state!.data!.game) === 'upcoming') body.startsAt = iso(this.startsAt());

    this.loading = true;
    const save = this.editing
      ? this.attrs.state!.update(body)
      : call<State>('POST', '/games', { ...body, discussionId: this.attrs.discussion?.id() });

    save
      .then((state) => {
        this.hide();
        if (this.editing) return;
        const discussion = this.attrs.discussion;
        if (discussion) {
          // Stay in the thread: reload it so the game and its "started" post show.
          app.store.find('discussions', discussion.id()!).then(() => (app.current.get('stream') as any)?.update?.());
          app.alerts.show({ type: 'success' }, t('form.created'));
        } else {
          m.route.set(gameUrl(state.game));
        }
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
