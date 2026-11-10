<?php

namespace ErnestDefoe\Bingo\Api;

use ErnestDefoe\Bingo\Pulse;
use ErnestDefoe\Bingo\Settings;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/bingo/games/{id}/pulse: the game's revision and the server's
 * clock, nothing else. It is what open cards poll when realtime is not
 * there, so it reads the cache and no table; a number says nothing about
 * the game, so it needs no visibility check (the state endpoint has one).
 */
class PulseController implements RequestHandlerInterface
{
    public function __construct(private readonly Pulse $pulse)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // A permission check only: no query. Who may see this particular game is the state endpoint's job.
        if (! RequestUtil::getActor($request)->can(Settings::VIEW)) {
            return new JsonResponse(['rev' => null], 403);
        }
        $revision = $this->pulse->revision((int) Arr::get($request->getQueryParams(), 'id'));

        return new JsonResponse(['rev' => $revision, 'now' => gmdate('Y-m-d\TH:i:s\Z')], $revision === null ? 404 : 200, ['Cache-Control' => 'no-store']);
    }
}
