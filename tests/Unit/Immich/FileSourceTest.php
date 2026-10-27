<?php

namespace Tests\Unit\Immich;

use App\Immich\Support\FileSource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fork feature: Immich library. See docs/fork/IMMICH.md
 */
class FileSourceTest extends TestCase
{
    /** Upstream's default MEDIA_TYPES. */
    const DEFAULT_TYPES = 'image/jpeg,image/jpg,image/png,image/gif';

    const MAX = 15000 * 1024;

    #[Test]
    public function an_acceptable_original_comes_first(): void
    {
        $this->assertSame(
            [FileSource::ORIGINAL, FileSource::FULLSIZE, FileSource::PREVIEW],
            FileSource::candidates('IMAGE', 'image/jpeg', 4_000_000, FileSource::normalizeList(self::DEFAULT_TYPES), self::MAX)
        );
    }

    #[Test]
    public function a_heic_original_falls_back_to_immichs_rendering(): void
    {
        $this->assertSame(
            [FileSource::FULLSIZE, FileSource::PREVIEW],
            FileSource::candidates('IMAGE', 'image/heic', 2_000_000, FileSource::normalizeList(self::DEFAULT_TYPES), self::MAX)
        );
    }

    #[Test]
    public function a_heic_original_is_used_where_the_instance_takes_heic(): void
    {
        $this->assertSame(
            FileSource::ORIGINAL,
            FileSource::candidates('IMAGE', 'image/heic', 2_000_000, ['image/jpeg', 'image/heic'], self::MAX)[0]
        );
    }

    #[Test]
    public function an_oversized_original_is_passed_over(): void
    {
        $this->assertSame(
            [FileSource::FULLSIZE, FileSource::PREVIEW],
            FileSource::candidates('IMAGE', 'image/jpeg', self::MAX + 1, ['image/jpeg'], self::MAX)
        );
    }

    #[Test]
    public function unknown_size_counts_as_fitting(): void
    {
        $this->assertSame(FileSource::ORIGINAL, FileSource::candidates('IMAGE', 'image/png', null, ['image/png', 'image/jpeg'], self::MAX)[0]);
    }

    #[Test]
    public function nothing_is_offered_if_no_rendering_could_be_accepted(): void
    {
        $this->assertSame([], FileSource::candidates('IMAGE', 'image/heic', 1, ['image/png'], self::MAX));
        $this->assertSame([FileSource::ORIGINAL], FileSource::candidates('IMAGE', 'image/png', 1, ['image/png'], self::MAX));
    }

    #[Test]
    public function videos_need_mp4_on_the_instance(): void
    {
        $this->assertSame([], FileSource::candidates('VIDEO', 'video/mp4', 1, ['image/jpeg'], self::MAX));
        $this->assertSame(
            [FileSource::ORIGINAL, FileSource::PLAYBACK],
            FileSource::candidates('VIDEO', 'video/mp4', 1, ['image/jpeg', 'video/mp4'], self::MAX)
        );
        $this->assertSame(
            [FileSource::PLAYBACK],
            FileSource::candidates('VIDEO', 'video/quicktime', 1, ['image/jpeg', 'video/mp4'], self::MAX)
        );
    }

    #[Test]
    public function other_asset_types_are_never_offered(): void
    {
        $this->assertSame([], FileSource::candidates('AUDIO', 'audio/mpeg', 1, ['audio/mpeg'], self::MAX));
        $this->assertSame([], FileSource::candidates('OTHER', 'image/jpeg', 1, ['image/jpeg'], self::MAX));
    }

    #[Test]
    public function mime_spellings_are_normalised(): void
    {
        $this->assertSame('image/jpeg', FileSource::normalizeMime('image/jpg'));
        $this->assertSame('image/jpeg', FileSource::normalizeMime(' Image/JPEG; charset=binary '));
        $this->assertSame(['image/jpeg', 'image/png'], FileSource::normalizeList('image/jpeg,image/jpg, image/png,'));
        $this->assertTrue(FileSource::acceptable('image/jpg', 10, ['image/jpeg'], 100));
        $this->assertFalse(FileSource::acceptable('', 10, ['image/jpeg'], 100));
    }

    #[Test]
    public function a_rendering_gets_the_extension_of_what_it_is(): void
    {
        $this->assertSame('IMG_0042.jpg', FileSource::filename('IMG_0042.HEIC', 'image/jpeg'));
        $this->assertSame('IMG_0042.webp', FileSource::filename('IMG_0042.HEIC', 'image/webp'));
        $this->assertSame('beach.png', FileSource::filename('beach.png', 'image/png'));
        $this->assertSame('immich.jpg', FileSource::filename(null, 'image/jpeg'));
        $this->assertSame('immich.jpg', FileSource::filename('', 'image/jpg'));
    }

    #[Test]
    public function a_file_name_cannot_carry_a_path_or_header_breaking_characters(): void
    {
        $name = FileSource::filename("../../etc/pa\"ss\r\nwd.jpg", 'image/jpeg');

        $this->assertStringNotContainsString('/', $name);
        $this->assertStringNotContainsString('"', $name);
        $this->assertStringNotContainsString("\n", $name);
        $this->assertStringEndsWith('.jpg', $name);
    }
}
