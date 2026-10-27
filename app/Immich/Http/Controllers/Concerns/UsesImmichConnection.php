<?php

namespace App\Immich\Http\Controllers\Concerns;

use App\Immich\Models\ImmichConnection;
use App\Immich\Services\ImmichException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fork feature: Immich library. See docs/fork/IMMICH.md
 */
trait UsesImmichConnection
{
    protected function connection(Request $request): ImmichConnection
    {
        abort_unless(config('immich.enabled'), 404);
        abort_if(! $request->user(), 403);

        $connection = ImmichConnection::whereUserId($request->user()->id)->first();

        if (! $connection) {
            abort(response()->json([
                'error' => 'not_connected',
                'message' => 'Connect your Immich library in Settings first.',
            ], 409));
        }

        return $connection;
    }

    /**
     * Immich's failures as JSON the picker and settings page can explain.
     *
     * Never 401 or 403: those would read as *Pixelfed* refusing the viewer,
     * and the front end treats them that way.
     */
    protected function immichError(ImmichException $e): JsonResponse
    {
        $status = match ($e->kind) {
            ImmichException::NOT_FOUND => 404,
            ImmichException::TOO_LARGE => 413,
            ImmichException::UNSUPPORTED => 422,
            default => 502,
        };

        return response()->json([
            'error' => $e->kind,
            'message' => $e->getMessage(),
            'permission' => $e->permission,
        ], $status);
    }
}
