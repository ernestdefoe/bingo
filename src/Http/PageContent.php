<?php

namespace ErnestDefoe\Bingo\Http;

use ErnestDefoe\Bingo\Game;
use ErnestDefoe\Bingo\Settings;
use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /bingo and /bingo/{id} as the server sends them: a title, a canonical
 * URL and noindex unless the admin allows indexing. The game itself needs
 * JavaScript.
 */
class PageContent
{
    public function __construct(
        private readonly UrlGenerator $url,
        private readonly TranslatorInterface $translator,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): Document
    {
        $hub = (string) $this->translator->trans('ernestdefoe-bingo.forum.hub.title');
        $id = (int) Arr::get($request->getQueryParams(), 'id');
        $game = $id ? Game::query()->whereVisibleTo(RequestUtil::getActor($request))->find($id) : null;

        if ($game) {
            $document->title = $game->title.' · '.$hub;
            $document->canonicalUrl = $this->url->to('forum')->route('bingo.game', ['id' => $game->id.'-'.$game->slug()]);
            if ($game->description) {
                $document->meta['description'] = mb_substr($game->description, 0, 160);
            }
        } else {
            $document->title = $hub;
            $document->canonicalUrl = $this->url->to('forum')->route('bingo');
        }

        if (! $this->settings->get(Settings::INDEX)) {
            $document->meta['robots'] = 'noindex, nofollow';
        }

        return $document;
    }
}
