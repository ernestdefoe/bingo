import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import LinkButton from 'flarum/common/components/LinkButton';
import Button from 'flarum/common/components/Button';
import DiscussionControls from 'flarum/forum/utils/DiscussionControls';
import type Discussion from 'flarum/common/models/Discussion';
import type Mithril from 'mithril';
import { t } from './api';
import DiscussionBingo from './components/DiscussionBingo';

export { default as extend } from './extend';

/*
 * forum.js is loaded on every page, so it only wires things up: the routes,
 * the sidebar link, the games under a discussion's title, the "Start a
 * Bingo game" control, the event posts and notifications. Pages, the card
 * and the forms are one chunk (play), fetched when first opened.
 */

const page = (name: 'HubPage' | 'GamePage') => () => import('./play').then(async (play) => (await play.loadCore(), { default: play[name] }));

app.initializers.add('ernestdefoe/bingo', () => {
  app.routes['bingo'] = { path: '/bingo', component: page('HubPage') };
  app.routes['bingo.game'] = { path: '/bingo/:id', component: page('GamePage') };

  // String form: these components live in lazy chunks, and extending an imported copy patches one core never renders.
  extend('flarum/forum/components/IndexSidebar', 'navItems', function (items: ItemList<Mithril.Children>) {
    if (!app.forum.attribute('bingoCanView') || !app.forum.attribute('bingoNav')) return;
    items.add('bingo', LinkButton.component({ href: app.route('bingo'), icon: 'fas fa-table-cells' }, t('nav')), 79);
  });

  extend('flarum/forum/components/DiscussionHero', 'bodyItems', function (this: any, items: ItemList<Mithril.Children>) {
    const discussion: Discussion = this.attrs.discussion;
    if ((discussion.attribute<any[]>('bingoGames') || []).length) {
      items.add('bingo', <DiscussionBingo discussion={discussion} />, 50);
    }
  });

  extend(DiscussionControls, 'userControls', function (items: ItemList<Mithril.Children>, discussion: Discussion) {
    if (!discussion.attribute('canStartBingo')) return;
    items.add(
      'bingo',
      <Button icon="fas fa-table-cells" onclick={() => import('./play').then((play) => play.openGameForm({ discussion }))}>
        {t('controls.start')}
      </Button>,
      -10
    );
  });

  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: ItemList<any>) {
    if (!app.forum.attribute('bingoCanView')) return;
    items.add('bingoReview', { name: 'bingoReview', icon: 'fas fa-table-cells', label: t('notification.review_preference') });
    items.add('bingoResult', { name: 'bingoResult', icon: 'fas fa-trophy', label: t('notification.result_preference') });
  });
});
