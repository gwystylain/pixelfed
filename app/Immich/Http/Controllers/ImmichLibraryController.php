<?php

namespace App\Immich\Http\Controllers;

use App\Geo\Services\StatusGeoService;
use App\Geo\Support\Coordinates;
use App\Http\Controllers\Controller;
use App\Immich\Http\Controllers\Concerns\UsesImmichConnection;
use App\Immich\Models\ImmichConnection;
use App\Immich\Services\ImmichClient;
use App\Immich\Services\ImmichException;
use App\Immich\Services\ImmichPeople;
use App\Immich\Support\AssetId;
use App\Immich\Support\FileSource;
use App\Immich\Support\SearchRequest;
use App\Immich\Support\ServerVersion;
use App\Models\Media;
use Illuminate\Http\Request;

/**
 * Fork feature: Immich library. See docs/fork/IMMICH.md
 *
 * What the composer's picker browses, and the file it takes away.
 *
 * The picker never uploads anything itself. It fetches the photo from here
 * as a file and hands it to upstream's composer exactly as if it had come
 * off the disk, so filters, cropping, alt text, limits and the upload are
 * all upstream's, unchanged.
 */
class ImmichLibraryController extends Controller
{
    use UsesImmichConnection;

    public function __construct(protected ImmichPeople $people)
    {
        $this->middleware('auth');
    }

    /**
     * Whether the picker should offer itself. No call to Immich: this runs
     * every time the composer opens.
     */
    public function status(Request $request)
    {
        abort_unless(config('immich.enabled'), 404);
        abort_if(! $request->user(), 403);

        $connection = ImmichConnection::whereUserId($request->user()->id)->first();

        return response()->json([
            'enabled' => true,
            'connected' => (bool) $connection,
            'settings_url' => route('settings.immich'),
            'videos' => $this->acceptsVideo(),
        ]);
    }

    public function albums(Request $request)
    {
        $connection = $this->connection($request);

        try {
            $albums = ImmichClient::for($connection)->albums();
        } catch (ImmichException $e) {
            // No album.read is a missing filter, not a broken picker.
            return response()->json(['albums' => [], 'error' => $e->getMessage()]);
        }

        $out = [];
        foreach ($albums as $album) {
            if (! AssetId::isValid($album['id'] ?? null)) {
                continue;
            }

            $out[] = [
                'id' => $album['id'],
                'name' => (string) ($album['albumName'] ?? 'Untitled'),
                'count' => (int) ($album['assetCount'] ?? 0),
            ];
        }

        usort($out, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return response()->json(['albums' => $out, 'error' => null]);
    }

    /**
     * A page of the library, newest first, or of a smart search.
     */
    public function assets(Request $request)
    {
        $connection = $this->connection($request);

        $this->validate($request, [
            'q' => 'nullable|string|max:200',
            'album' => 'nullable|string|max:64',
            'next' => 'nullable|string|max:520',
        ]);

        $album = $request->input('album');
        $next = $request->input('next');

        abort_if($album && ! AssetId::isValid($album), 422, 'Invalid album');
        abort_unless(SearchRequest::isToken($next), 422, 'Invalid page');

        $client = ImmichClient::for($connection);
        $version = ServerVersion::parse($connection->server_version);
        $videos = $this->acceptsVideo();
        $size = max(1, min(200, (int) config('immich.page_size', 60)));
        $query = trim((string) $request->input('q'));

        try {
            $page = $query !== ''
                ? $client->smartSearch($version, $query, $next, $size, $videos)
                : $client->library($version, $album, $next, $size, $videos);
        } catch (ImmichException $e) {
            return $this->immichError($e);
        }

        $items = [];
        foreach ($page['items'] as $asset) {
            $type = strtoupper((string) ($asset['type'] ?? ''));

            if (! AssetId::isValid($asset['id'] ?? null)) {
                continue;
            }

            if ($type !== 'IMAGE' && ! ($type === 'VIDEO' && $videos)) {
                continue;
            }

            $items[] = [
                'id' => $asset['id'],
                'type' => strtolower($type),
                'filename' => (string) ($asset['originalFileName'] ?? ''),
                'taken_at' => $asset['localDateTime'] ?? $asset['fileCreatedAt'] ?? null,
                'duration' => self::seconds($asset['duration'] ?? null),
                'thumbnail' => '/api/immich/v1/assets/'.$asset['id'].'/thumbnail',
            ];
        }

        return response()->json([
            'items' => $items,
            'next' => $page['next'],
        ]);
    }

    /**
     * One asset: what the composer needs before it fetches the file — the
     * name to give it, and who is in it.
     */
    public function asset(Request $request, string $id)
    {
        $connection = $this->connection($request);
        abort_unless(AssetId::isValid($id), 404);

        try {
            $asset = ImmichClient::for($connection)->asset($id);
        } catch (ImmichException $e) {
            return $this->immichError($e);
        }

        $exif = is_array($asset['exifInfo'] ?? null) ? $asset['exifInfo'] : [];
        $lat = $exif['latitude'] ?? null;
        $lng = $exif['longitude'] ?? null;

        return response()->json([
            'id' => $id,
            'type' => strtolower((string) ($asset['type'] ?? '')),
            'filename' => (string) ($asset['originalFileName'] ?? ''),
            'mime' => $asset['originalMimeType'] ?? null,
            'taken_at' => $asset['localDateTime'] ?? null,
            'location' => Coordinates::isValid($lat, $lng) ? ['lat' => (float) $lat, 'lng' => (float) $lng] : null,
            'importable' => ! empty($this->candidates($asset)),
            'people' => $this->people->suggestionsFor($request->user(), (array) ($asset['people'] ?? [])),
            'file_url' => '/api/immich/v1/assets/'.$id.'/file',
        ]);
    }

    public function thumbnail(Request $request, string $id)
    {
        $connection = $this->connection($request);
        abort_unless(AssetId::isValid($id), 404);

        try {
            $file = ImmichClient::for($connection)->small('assets/'.$id.'/thumbnail', ['size' => 'thumbnail']);
        } catch (ImmichException $e) {
            return $this->immichError($e);
        }

        abort_unless(str_starts_with($file['mime'], 'image/'), 502);

        return response($file['body'], 200, [
            'Content-Type' => $file['mime'],
            'Cache-Control' => 'private, max-age='.(int) config('immich.thumbnail_cache_seconds', 86400),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The best copy of the asset this instance will accept, as a download.
     * See App\Immich\Support\FileSource for the order things are tried in.
     */
    public function file(Request $request, string $id)
    {
        $connection = $this->connection($request);
        abort_unless(AssetId::isValid($id), 404);

        $client = ImmichClient::for($connection);

        try {
            $asset = $client->asset($id);
        } catch (ImmichException $e) {
            return $this->immichError($e);
        }

        $accepted = $this->acceptedMimes();
        $maxBytes = $this->maxBytes();
        $candidates = $this->candidates($asset);

        if (empty($candidates)) {
            return $this->immichError(new ImmichException(
                'This instance does not accept '.($asset['originalMimeType'] ?? 'this kind of file').', and Immich has no copy of it that it does.',
                ImmichException::UNSUPPORTED
            ));
        }

        $last = null;

        foreach ($candidates as $variant) {
            [$path, $query] = $this->variantPath($id, $variant);
            $tmp = tempnam(sys_get_temp_dir(), 'immich');

            try {
                $meta = $client->download($path, $query, $tmp, $maxBytes);
            } catch (ImmichException $e) {
                @unlink($tmp);

                // A bad key or a dead server will not do better on the next
                // variant. Anything else might: an old server that has no
                // `fullsize`, a key without asset.download that can still
                // view previews, an original that is too big.
                if (in_array($e->kind, [ImmichException::UNAUTHORIZED, ImmichException::UNREACHABLE], true)) {
                    return $this->immichError($e);
                }

                $last = $e;

                continue;
            }

            $mime = FileSource::normalizeMime($meta['mime']);

            if ($mime === '' || $mime === 'application/octet-stream') {
                $mime = FileSource::normalizeMime((string) @mime_content_type($tmp));
            }

            if (! FileSource::acceptable($mime, $meta['bytes'], $accepted, $maxBytes)) {
                @unlink($tmp);
                $last = new ImmichException('Immich only had this as '.($mime ?: 'an unknown type').', which this instance does not accept.', ImmichException::UNSUPPORTED);

                continue;
            }

            return response()
                ->download($tmp, FileSource::filename($asset['originalFileName'] ?? null, $mime), [
                    'Content-Type' => $mime,
                    'Cache-Control' => 'private, no-store',
                    'X-Content-Type-Options' => 'nosniff',
                    'X-Immich-Variant' => $variant,
                ])
                ->deleteFileAfterSend(true);
        }

        // $candidates is not empty, and every pass either returned or set this.
        return $this->immichError($last);
    }

    /**
     * Called by the composer between uploading and publishing: gives draft
     * uploads that came from Immich the coordinates Immich has for them.
     *
     * The geo feature reads GPS from a photo's EXIF, which an Immich import
     * may not have by the time it is uploaded — a HEIC original swapped for
     * Immich's JPEG rendering, or any photo after a filter or crop, since
     * the browser re-encodes it. Immich read the original's EXIF when it was
     * imported, so it still knows. Never overrides coordinates the upload
     * had itself, and never touches an upload the author opted off the map.
     */
    public function attach(Request $request)
    {
        $connection = $this->connection($request);

        $this->validate($request, [
            'items' => 'required|array|max:20',
            'items.*.media_id' => 'required|regex:/^[0-9]{1,20}$/',
            'items.*.asset_id' => 'required|string|max:64',
        ]);

        if (! config('geo.enabled')) {
            return response()->json(['located' => 0]);
        }

        $client = ImmichClient::for($connection);
        $located = 0;

        foreach ($request->input('items') as $item) {
            if (! AssetId::isValid($item['asset_id'])) {
                continue;
            }

            $media = Media::whereProfileId($request->user()->profile_id)
                ->whereNull('status_id')
                ->find($item['media_id']);

            if (! $media || $media->geo_lat !== null || $media->geo_precision === StatusGeoService::PRECISION_NONE) {
                continue;
            }

            try {
                $exif = $client->asset($item['asset_id'])['exifInfo'] ?? [];
            } catch (ImmichException) {
                continue;
            }

            $lat = $exif['latitude'] ?? null;
            $lng = $exif['longitude'] ?? null;

            if (! Coordinates::isValid($lat, $lng)) {
                continue;
            }

            $media->geo_lat = (float) $lat;
            $media->geo_lng = (float) $lng;
            $media->geo_extracted_at ??= now();
            // Quietly, as MediaGeoService does: nothing observing media
            // cares about a draft's coordinates until it is attached.
            $media->saveQuietly();
            $located++;
        }

        return response()->json(['located' => $located]);
    }

    /**
     * @return array<int, string>
     */
    protected function candidates(array $asset): array
    {
        $exif = is_array($asset['exifInfo'] ?? null) ? $asset['exifInfo'] : [];

        return FileSource::candidates(
            (string) ($asset['type'] ?? ''),
            $asset['originalMimeType'] ?? null,
            isset($exif['fileSizeInByte']) ? (int) $exif['fileSizeInByte'] : null,
            $this->acceptedMimes(),
            $this->maxBytes()
        );
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    protected function variantPath(string $id, string $variant): array
    {
        return match ($variant) {
            FileSource::ORIGINAL => ['assets/'.$id.'/original', []],
            FileSource::PLAYBACK => ['assets/'.$id.'/video/playback', []],
            default => ['assets/'.$id.'/thumbnail', ['size' => $variant]],
        };
    }

    /**
     * @return array<int, string>
     */
    protected function acceptedMimes(): array
    {
        return FileSource::normalizeList((string) config_cache('pixelfed.media_types'));
    }

    protected function maxBytes(): int
    {
        return (int) config_cache('pixelfed.max_photo_size') * 1024;
    }

    protected function acceptsVideo(): bool
    {
        return in_array('video/mp4', $this->acceptedMimes(), true);
    }

    /**
     * Immich reports duration as milliseconds since 3.0 and as "0:00:12.345"
     * before it.
     */
    protected static function seconds(mixed $duration): ?int
    {
        if (is_int($duration) || (is_string($duration) && ctype_digit($duration))) {
            return (int) round(((int) $duration) / 1000);
        }

        if (is_string($duration) && preg_match('/^(\d+):(\d{2}):(\d{2})(?:\.\d+)?$/', $duration, $m)) {
            $seconds = ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) $m[3];

            return $seconds > 0 ? $seconds : null;
        }

        return null;
    }
}
