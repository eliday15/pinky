<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate requests from the local Python sync agent.
 *
 * Uses a shared secret (Bearer token) configured in config/zkteco.php.
 * No Sanctum or session required — the agent is a machine-to-machine client.
 */
class SyncAgentAuth
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request The incoming HTTP request
     * @param Closure $next The next middleware handler
     * @return Response|JsonResponse
     */
    public function handle(Request $request, Closure $next): Response
    {
        $agentKey = config('zkteco.sync.agent_key');

        if (empty($agentKey) || $request->bearerToken() !== $agentKey) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
