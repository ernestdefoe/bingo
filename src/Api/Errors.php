<?php

namespace ErnestDefoe\Bingo\Api;

use Laminas\Diactoros\Response\JsonResponse;

/** Error answers in Flarum's shape; the browser turns each code into a sentence. */
final class Errors
{
    public static function make(int $status, string $code): JsonResponse
    {
        return new JsonResponse(['errors' => [['status' => (string) $status, 'code' => $code]]], $status);
    }
}
