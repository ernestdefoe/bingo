import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import type Mithril from 'mithril';
import { MEDALS, placeName, t } from './api';

const href = (notification: any) => app.route('bingo.game', { id: String(notification.content()?.game ?? '') });

/** To the host: a call to rule on, or a dispute. */
export class ReviewNotification extends Notification {
  icon() {
    return 'fas fa-table-cells';
  }

  href() {
    return href(this.attrs.notification);
  }

  content(): Mithril.Children {
    const notification: any = this.attrs.notification;
    const data = notification.content() || {};
    return t(data.kind === 'dispute' ? 'notification.dispute' : 'notification.call', {
      username: notification.fromUser()?.displayName(),
      title: data.title,
    });
  }

  excerpt(): Mithril.Children {
    return null;
  }
}

/** To the caller: how a call that waited came out. */
export class ResultNotification extends Notification {
  icon() {
    return 'fas fa-trophy';
  }

  href() {
    return href(this.attrs.notification);
  }

  content(): Mithril.Children {
    const data: any = (this.attrs.notification as any).content() || {};
    if (data.status === 'rejected') return t('notification.rejected', { title: data.title });
    if (data.place) return t('notification.won', { title: data.title, place: [MEDALS[data.place - 1], ' ', placeName(data.place)] });
    return t('notification.accepted', { title: data.title });
  }

  excerpt(): Mithril.Children {
    const data: any = (this.attrs.notification as any).content() || {};
    return data.status === 'rejected' && data.note ? data.note : null;
  }
}
