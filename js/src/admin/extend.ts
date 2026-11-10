import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';

const t = (key: string) => app.translator.trans(`ernestdefoe-bingo.admin.${key}`, {}, true);
const setting = (key: string) => `ernestdefoe-bingo.${key}`;

export default [
  new Extend.Admin()
    .setting(() => ({ setting: setting('nav'), type: 'boolean', label: t('settings.nav') }), 100)
    .setting(
      () => ({ setting: setting('allow_indexing'), type: 'boolean', label: t('settings.allow_indexing'), help: t('settings.allow_indexing_help') }),
      90
    )
    .permission(() => ({ permission: 'bingo.view', icon: 'fas fa-table-cells', label: t('permissions.view'), allowGuest: true }), 'view', 60)
    .permission(() => ({ permission: 'bingo.play', icon: 'fas fa-hand-pointer', label: t('permissions.play') }), 'reply', 60)
    .permission(() => ({ permission: 'bingo.create', icon: 'fas fa-table-cells', label: t('permissions.create') }), 'start', 60)
    .permission(() => ({ permission: 'bingo.moderate', icon: 'fas fa-gavel', label: t('permissions.moderate') }), 'moderate', 60),
];
