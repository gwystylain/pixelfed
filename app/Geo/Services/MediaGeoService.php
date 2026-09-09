<?php

namespace App\Geo\Services;

use App\Geo\Support\Coordinates;
use App\Geo\Support\ExifGpsReader;
use App\Media;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
 *
 * Captures GPS coordinates from an upload and stores them on the media row.
 *
 * Timing matters. The resize pipeline re-encodes images through Intervention
 * Image, which drops all metadata, and for JPEG/PNG/WebP it writes back over
 * the original path. So this has to run before `ImageOptimize` is dispatched,
 * which is why it hangs off `Media::created` rather than the pipeline.
 *
 * Only the coordinates are kept. The published file stays stripped, so
 * enabling the geo feed does not start handing every downloader the EXIF
 * from an author's camera.
 */
class MediaGeoService
{
    /**
     * Formats worth looking inside. HEIC/HEIF matters most: it is the iPhone
     * default and the usual source of photo GPS.
     *
     * @var list<string>
     */
    const SUPPORTED_MIMES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
        'image/avif',
        'image/heic',
        'image/heif',
        'image/tiff',
    ];

    public function enabled(): bool
    {
        return (bool) config('geo.enabled') && (bool) config('geo.exif.enabled');
    }

    public function shouldExtract(?Media $media): bool
    {
        if (! $this->enabled() || ! $media) {
            return false;
        }

        if ($media->geo_extracted_at || ! $media->media_path) {
            return false;
        }

        // Remote media is somebody else's file on somebody else's terms.
        if ($media->remote_media) {
            return false;
        }

        return in_array(strtolower((string) $media->mime), self::SUPPORTED_MIMES, true);
    }

    /**
     * Read GPS out of the stored original and persist it.
     *
     * Returns true when coordinates were found. `geo_extracted_at` is set
     * either way, so a photo without GPS is not re-read on every pass.
     */
    public function extract(Media $media): bool
    {
        if (! $this->shouldExtract($media)) {
            return false;
        }

        $gps = null;

        try {
            $gps = $this->read($media);
        } catch (\Throwable $e) {
            if (config('app.dev_log')) {
                Log::info('geo: EXIF GPS read failed for media '.$media->id.': '.$e->getMessage());
            }
        }

        $media->geo_extracted_at = now();

        if ($gps && Coordinates::isValid($gps['lat'], $gps['lng'])) {
            $media->geo_lat = $gps['lat'];
            $media->geo_lng = $gps['lng'];
        }

        // saveQuietly: this runs from the model's own created/updated hook,
        // and re-firing observers here would recurse.
        $media->saveQuietly();

        return $media->geo_lat !== null;
    }

    /**
     * Discard stored coordinates, e.g. when the author opts a post out.
     */
    public function forget(Media $media): void
    {
        $media->geo_lat = null;
        $media->geo_lng = null;
        $media->saveQuietly();
    }

    /**
     * @return array{lat: float, lng: float, altitude: float|null}|null
     */
    protected function read(Media $media): ?array
    {
        $maxBytes = (int) config('geo.exif.max_read_bytes', 8388608);
        $disk = config('filesystems.default');

        if ($disk === 'local') {
            return ExifGpsReader::fromFile(
                storage_path('app/'.$media->media_path),
                $maxBytes
            );
        }

        $bytes = $this->readRemote($disk, $media->media_path, $maxBytes);

        return $bytes === null ? null : ExifGpsReader::fromBytes($bytes);
    }

    /**
     * Pull at most $maxBytes off a remote disk. For S3 style drivers this is
     * a ranged read, so a large photo does not have to come down in full.
     */
    protected function readRemote(string $disk, string $path, int $maxBytes): ?string
    {
        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            return null;
        }

        $stream = $storage->readStream($path);
        if (! $stream) {
            return null;
        }

        try {
            $bytes = stream_get_contents($stream, $maxBytes);
        } finally {
            fclose($stream);
        }

        return $bytes === false ? null : $bytes;
    }
}
