import app from 'flarum/forum/app';

/*
 * Everything a member only needs once they open a game: the pages, the
 * card, the forms. One chunk, fetched on first use; forum.js stays small.
 */

declare const flarum: any;

/** Core page parts, loaded through the registry before a page draws (they are lazy in Flarum 2). */
export let PageStructure: any;
export let IndexSidebar: any;

export async function loadCore(): Promise<void> {
  if (PageStructure) return;
  const [structure, sidebar] = await Promise.all([
    flarum.reg.asyncModuleImport('flarum/forum/components/PageStructure'),
    flarum.reg.asyncModuleImport('flarum/forum/components/IndexSidebar'),
  ]);
  PageStructure = structure.default || structure;
  IndexSidebar = sidebar.default || sidebar;
}

export function openGameForm(attrs: Record<string, any>): void {
  app.modal.show(GameModal, attrs);
}

import GameModal from './components/GameModal';
export { GameModal };
export { default as HubPage } from './components/HubPage';
export { default as GamePage } from './components/GamePage';
export { default as CardModal } from './components/CardModal';
