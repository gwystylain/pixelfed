<?php

namespace App\Immich\Services;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Fork feature: Immich library. See docs/fork/IMMICH.md
 *
 * Something went wrong talking to Immich, with a message fit to show the
 * person whose Immich it is.
 */
class ImmichException extends RuntimeException
{
    const UNREACHABLE = 'unreachable';

    const UNAUTHORIZED = 'unauthorized';

    const FORBIDDEN = 'forbidden';

    const NOT_FOUND = 'not_found';

    const BAD_REQUEST = 'bad_request';

    const TOO_LARGE = 'too_large';

    const UNSUPPORTED = 'unsupported';

    const ERROR = 'error';

    public function __construct(
        string $message,
        public readonly string $kind = self::ERROR,
        public readonly int $upstreamStatus = 0,
        public readonly ?string $permission = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function unreachable(string $baseUrl, ?\Throwable $previous = null): self
    {
        return new self(
            'Could not reach Immich at '.$baseUrl.'. Check the address, and that the Pixelfed server (not just your browser) can reach it.',
            self::UNREACHABLE,
            0,
            null,
            $previous
        );
    }

    public static function tooLarge(int $maxBytes): self
    {
        return new self(
            'The file is larger than this instance accepts ('.round($maxBytes / 1048576, 1).' MB).',
            self::TOO_LARGE
        );
    }

    public static function fromResponse(Response $response): self
    {
        $status = $response->status();
        $json = rescue(fn () => $response->json(), null, false);
        $detail = is_array($json) ? ($json['message'] ?? null) : null;

        if (is_array($detail)) {
            $detail = implode('; ', array_filter($detail, 'is_string'));
        }

        $detail = is_string($detail) ? mb_substr($detail, 0, 300) : null;

        // "Missing required permission: asset.read"
        $permission = null;
        if ($detail && preg_match('/permission:\s*([a-zA-Z.]+)/', $detail, $m)) {
            $permission = $m[1];
        }

        return match (true) {
            $status === 401 => new self('Immich rejected the API key. It may have been deleted or mistyped.', self::UNAUTHORIZED, $status),
            $status === 403 => new self(
                $permission
                    ? 'The API key is missing the "'.$permission.'" permission.'
                    : 'Immich refused: '.($detail ?: 'forbidden').'.',
                self::FORBIDDEN,
                $status,
                $permission
            ),
            $status === 404 => new self('Immich could not find that.', self::NOT_FOUND, $status),
            $status === 400 => new self('Immich did not understand the request'.($detail ? ': '.$detail : '.'), self::BAD_REQUEST, $status),
            default => new self('Immich returned an error ('.$status.')'.($detail ? ': '.$detail : '.'), self::ERROR, $status),
        };
    }
}
