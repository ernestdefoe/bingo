import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Stream from 'flarum/common/utils/Stream';
import type Mithril from 'mithril';
import { t } from '../api';

interface Attrs extends IFormModalAttrs {
  title: Mithril.Children;
  help: Mithril.Children;
  onsubmit: (text: string) => Promise<unknown>;
}

/** One optional sentence: why a call is disputed or turned down. */
export default class ReasonModal extends FormModal<Attrs> {
  text = Stream('');

  className() {
    return 'BingoReasonModal Modal--small';
  }

  title() {
    return this.attrs.title;
  }

  content() {
    return (
      <div className="Modal-body">
        <div className="Form">
          <p className="helpText">{this.attrs.help}</p>
          <div className="Form-group">
            <textarea className="FormControl" rows={3} maxlength={200} bidi={this.text} aria-label={this.attrs.help} />
          </div>
          <div className="Form-group">
            <Button className="Button Button--primary Button--block" type="submit" loading={this.loading}>
              {t('reason.send')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;
    this.attrs
      .onsubmit(this.text().trim())
      .then(() => this.hide())
      .catch(() => {})
      .finally(() => {
        this.loading = false;
      });
  }
}
