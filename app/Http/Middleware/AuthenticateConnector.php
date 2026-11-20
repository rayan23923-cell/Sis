<?php

namespace App\Http\Middleware;

use App\Models\Connector;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The door for systems that call SIS (route middleware "connector:exams,rosters").
 * The caller sends the key SIS issued as a Bearer token. It is refused when the key is wrong, when the
 * connector is disconnected, or when that tool is switched off — so «إلغاء الربط» takes effect at once.
 */
class AuthenticateConnector
{
    public function handle(Request $request, Closure $next, string $key, ?string $tool = null): Response
    {
        $token = (string) $request->bearerToken();
        $c = Connector::where('key', $key)->first();

        if ($token === '' || ! $c || ! $c->isConnected() || ! $c->incoming_token_hash || ! hash_equals($c->incoming_token_hash, hash('sha256', $token))) {
            return response()->json(['message' => 'Unauthenticated connector.'], 401);
        }
        if ($tool !== null && ! $c->toolOn($tool)) {
            return response()->json(['message' => "The tool \"{$tool}\" is switched off in SIS.", 'tool' => $tool], 403);
        }

        $c->seen();
        $request->attributes->set('connector', $c);

        return $next($request);
    }
}
