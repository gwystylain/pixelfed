<?php

namespace App\Immich\Services;

use App\Immich\Models\ImmichConnection;
use App\Immich\Support\ImmichUrl;
use App\Immich\Support\SearchRequest;
use App\Immich\Support\ServerVersion;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;

/**
 * Fork feature: Immich library. See docs/fork/IMMICH.md
 *
 * The only thing that talks to Immich. Every request carries the account's
 * API key in `x-api-key`, and is made by this server — the browser never
 * sees the key, and never needs to reach Immich itself.
 */
class ImmichClient
{
    /** Pages of people fetched at most when listing them for mappings. */
    const MAX_PEOPLE_PAGES = 20;

    const PEOPLE_PAGE_SIZE = 500;

    public function __construct(
        protected string $baseUrl,
        #[\SensitiveParameter] protected string $apiKey,
    ) {}

    public static function for(ImmichConnection $connection): self
    {
        return new self($connection->base_url, $connection->api_key);
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Reports the server version. Public on every Immich version, so a
     * failure here means the address is wrong, not the key.
     */
    public function version(): ?ServerVersion
    {
        try {
            return ServerVersion::parse($this->getJson('server/version'));
        } catch (ImmichException $e) {
            if ($e->kind === ImmichException::NOT_FOUND) {
                throw new ImmichException(
                    'Something answered at '.$this->baseUrl.', but it is not Immich. Check the address and port.',
                    ImmichException::ERROR,
                    404
                );
            }

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function me(): array
    {
        return $this->getJson('users/me');
    }

    /**
     * Newest first, optionally inside one album.
     *
     * @return array{items: array<int, array<string, mixed>>, next: string|null}
     */
    public function library(?ServerVersion $version, ?string $albumId, ?string $token, int $size, bool $videos): array
    {
        $res = $this->postJson('search/metadata', SearchRequest::library($version, $albumId, $token, $size, $videos));

        return $this->searchPage($res);
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, next: string|null}
     */
    public function smartSearch(?ServerVersion $version, string $query, ?string $token, int $size, bool $videos): array
    {
        $res = $this->postJson('search/smart', SearchRequest::smart($version, $query, $token, $size, $videos));

        return $this->searchPage($res);
    }

    /**
     * One asset, with its EXIF and the people recognised in it.
     *
     * @return array<string, mixed>
     */
    public function asset(string $id): array
    {
        return $this->getJson('assets/'.$id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function albums(): array
    {
        $albums = $this->getJson('albums');

        return array_values(array_filter($albums, 'is_array'));
    }

    /**
     * Every person Immich knows, hidden ones excluded, across all pages.
     *
     * @return array<int, array<string, mixed>>
     */
    public function people(): array
    {
        $people = [];

        for ($page = 1; $page <= self::MAX_PEOPLE_PAGES; $page++) {
            $res = $this->getJson('people', [
                'withHidden' => 'false',
                'page' => $page,
                'size' => self::PEOPLE_PAGE_SIZE,
            ]);

            foreach ($res['people'] ?? [] as $person) {
                if (is_array($person)) {
                    $people[] = $person;
                }
            }

            // Servers from before paging return everything with no flag.
            if (empty($res['hasNextPage'])) {
                break;
            }
        }

        return $people;
    }

    /**
     * A small file read whole into memory: a thumbnail or a face.
     *
     * @return array{body: string, mime: string}
     */
    public function small(string $path, array $query = []): array
    {
        $response = $this->send(fn () => $this->http()->accept('*/*')->get($path, $query));

        return [
            'body' => $response->body(),
            'mime' => strtolower(trim(explode(';', $response->header('Content-Type'))[0])),
        ];
    }

    /**
     * Download to a file on disk, refusing anything over $maxBytes before
     * its body is read when Immich says how long it is, and after when not.
     *
     * @return array{mime: string, bytes: int}
     */
    public function download(string $path, array $query, string $sinkPath, int $maxBytes): array
    {
        $response = $this->send(fn () => $this->http((int) config('immich.download_timeout', 120))
            ->accept('*/*')
            ->withOptions([
                'sink' => $sinkPath,
                'on_headers' => function (ResponseInterface $r) use ($maxBytes) {
                    $length = (int) $r->getHeaderLine('Content-Length');

                    if ($r->getStatusCode() < 300 && $maxBytes > 0 && $length > $maxBytes) {
                        throw ImmichException::tooLarge($maxBytes);
                    }
                },
            ])
            ->get($path, $query));

        clearstatcache(true, $sinkPath);
        $bytes = (int) @filesize($sinkPath);

        if ($maxBytes > 0 && $bytes > $maxBytes) {
            throw ImmichException::tooLarge($maxBytes);
        }

        return [
            'mime' => strtolower(trim(explode(';', $response->header('Content-Type'))[0])),
            'bytes' => $bytes,
        ];
    }

    /**
     * Find out what the key can do, by asking for one thing of each kind.
     *
     * Each permission comes back true (allowed), false (Immich said the key
     * lacks it) or null (could not be checked — an empty library has no
     * asset to try a thumbnail on). An unreachable server or a rejected key
     * throws instead: there is nothing to report about a key that does not
     * work at all.
     *
     * @return array{version: ServerVersion|null, user: array{name: string|null, email: string|null}|null, permissions: array<string, bool|null>}
     */
    public function probe(): array
    {
        $version = $this->version();
        $permissions = [];
        $user = null;

        $permissions['user.read'] = $this->can(function () use (&$user) {
            $me = $this->me();
            $user = [
                'name' => isset($me['name']) ? (string) $me['name'] : null,
                'email' => isset($me['email']) ? (string) $me['email'] : null,
            ];
        });

        $assetId = null;
        $permissions['asset.read'] = $this->can(function () use ($version, &$assetId) {
            $page = $this->library($version, null, null, 1, false);
            $assetId = $page['items'][0]['id'] ?? null;
        });

        $permissions['asset.view'] = $assetId === null ? null : $this->can(
            fn () => $this->small('assets/'.$assetId.'/thumbnail', ['size' => 'thumbnail'])
        );

        // One byte is enough to learn whether the download is allowed.
        $permissions['asset.download'] = $assetId === null ? null : $this->can(
            fn () => $this->send(fn () => $this->http()
                ->accept('*/*')
                ->withHeaders(['Range' => 'bytes=0-0'])
                ->withOptions(['stream' => true])
                ->get('assets/'.$assetId.'/original'))
                ->toPsrResponse()->getBody()->close()
        );

        $permissions['person.read'] = $this->can(fn () => $this->getJson('people', ['withHidden' => 'false', 'size' => 1]));
        $permissions['album.read'] = $this->can(fn () => $this->getJson('albums'));

        return [
            'version' => $version,
            'user' => $user,
            'permissions' => $permissions,
        ];
    }

    /**
     * @return array<mixed>
     */
    public function getJson(string $path, array $query = []): array
    {
        return $this->decode($this->send(fn () => $this->http()->get($path, $query)));
    }

    /**
     * @return array<mixed>
     */
    public function postJson(string $path, array $body): array
    {
        return $this->decode($this->send(fn () => $this->http()->asJson()->post($path, $body)));
    }

    protected function http(?int $timeout = null): PendingRequest
    {
        return Http::baseUrl($this->baseUrl.'/api')
            ->withHeaders([
                'x-api-key' => $this->apiKey,
                'User-Agent' => 'Pixelfed/'.config('pixelfed.version').' (+'.config('app.url').')',
            ])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout($timeout ?? (int) config('immich.timeout', 10))
            ->withOptions([
                'allow_redirects' => [
                    'max' => 3,
                    'protocols' => ['http', 'https'],
                    // Immich redirects within itself (a `fullsize` request
                    // for a JPEG lands on /original). Anywhere else would
                    // take the key header with it.
                    'on_redirect' => function ($request, $response, $uri) {
                        if (! ImmichUrl::sameOrigin($this->baseUrl, (string) $uri)) {
                            throw new ImmichException('Immich redirected to another host; not following.', ImmichException::ERROR);
                        }
                    },
                ],
            ]);
    }

    /**
     * Run a request, turning every way it can fail into an ImmichException.
     */
    protected function send(callable $request): Response
    {
        try {
            $response = $request();
        } catch (ImmichException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Guzzle wraps what on_headers and on_redirect throw.
            for ($prev = $e; $prev; $prev = $prev->getPrevious()) {
                if ($prev instanceof ImmichException) {
                    throw $prev;
                }
            }

            if ($e instanceof ConnectionException || $e instanceof TransferException) {
                throw ImmichException::unreachable($this->baseUrl, $e);
            }

            throw $e;
        }

        if ($response->failed()) {
            throw ImmichException::fromResponse($response);
        }

        return $response;
    }

    /**
     * @return array<mixed>
     */
    protected function decode(Response $response): array
    {
        $json = $response->json();

        if (! is_array($json)) {
            throw new ImmichException('Immich sent something that was not JSON. Is that address an Immich server?', ImmichException::ERROR, $response->status());
        }

        return $json;
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, next: string|null}
     */
    protected function searchPage(array $res): array
    {
        $assets = is_array($res['assets'] ?? null) ? $res['assets'] : [];

        return [
            'items' => array_values(array_filter($assets['items'] ?? [], 'is_array')),
            'next' => SearchRequest::nextToken($assets),
        ];
    }

    /**
     * True if the call works, false if Immich says the key may not, null if
     * it failed for some other reason. A rejected key is rethrown.
     */
    protected function can(callable $call): ?bool
    {
        try {
            $call();

            return true;
        } catch (ImmichException $e) {
            if ($e->kind === ImmichException::UNAUTHORIZED || $e->kind === ImmichException::UNREACHABLE) {
                throw $e;
            }

            return $e->kind === ImmichException::FORBIDDEN ? false : null;
        }
    }
}
