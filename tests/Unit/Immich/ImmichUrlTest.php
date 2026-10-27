<?php

namespace Tests\Unit\Immich;

use App\Immich\Support\AssetId;
use App\Immich\Support\ImmichUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fork feature: Immich library. See docs/fork/IMMICH.md
 */
class ImmichUrlTest extends TestCase
{
    public static function accepted(): array
    {
        return [
            'web ui address' => ['http://192.168.1.10:2283', 'http://192.168.1.10:2283'],
            'trailing slash' => ['http://192.168.1.10:2283/', 'http://192.168.1.10:2283'],
            'api base' => ['http://192.168.1.10:2283/api', 'http://192.168.1.10:2283'],
            'api base, slash' => ['https://photos.example.com/api/', 'https://photos.example.com'],
            'no scheme' => ['immich.lan:2283', 'http://immich.lan:2283'],
            'case' => ['HTTPS://Photos.Example.COM', 'https://photos.example.com'],
            'sub path' => ['https://example.com/immich/', 'https://example.com/immich'],
            'whitespace' => ['  http://nas:30041  ', 'http://nas:30041'],
            'ipv6' => ['http://[fd00::1]:2283', 'http://[fd00::1]:2283'],
        ];
    }

    #[Test]
    #[DataProvider('accepted')]
    public function it_normalizes_what_people_paste(string $input, string $expected): void
    {
        $this->assertSame($expected, ImmichUrl::normalize($input));
    }

    public static function rejected(): array
    {
        return [
            'empty' => [''],
            'ftp' => ['ftp://nas/'],
            'file' => ['file:///etc/passwd'],
            'credentials' => ['http://user:pass@nas:2283'],
            'query' => ['http://nas:2283/?x=1'],
            'fragment' => ['http://nas:2283/#x'],
            'bad host' => ['http://na s:2283'],
            'underscore host' => ['http://bad_host/'],
            'javascript' => ['javascript:alert(1)'],
        ];
    }

    #[Test]
    #[DataProvider('rejected')]
    public function it_rejects_what_cannot_be_a_server(string $input): void
    {
        $this->assertNull(ImmichUrl::normalize($input));
    }

    #[Test]
    public function an_empty_allow_list_allows_everything(): void
    {
        $this->assertTrue(ImmichUrl::hostAllowed('http://10.0.0.5:2283', []));
    }

    #[Test]
    public function the_allow_list_matches_hosts_ports_and_wildcards(): void
    {
        $allowed = ['192.168.1.10:2283', 'photos.example.com', '*.lan'];

        $this->assertTrue(ImmichUrl::hostAllowed('http://192.168.1.10:2283', $allowed));
        $this->assertFalse(ImmichUrl::hostAllowed('http://192.168.1.10:8080', $allowed));
        $this->assertTrue(ImmichUrl::hostAllowed('https://photos.example.com', $allowed));
        $this->assertTrue(ImmichUrl::hostAllowed('https://photos.example.com:8443', $allowed));
        $this->assertTrue(ImmichUrl::hostAllowed('http://immich.lan:2283', $allowed));
        $this->assertFalse(ImmichUrl::hostAllowed('http://lan', $allowed));
        $this->assertFalse(ImmichUrl::hostAllowed('http://evil.example', $allowed));
        $this->assertFalse(ImmichUrl::hostAllowed('http://photos.example.com.evil.example', $allowed));
    }

    #[Test]
    public function same_origin_compares_scheme_host_and_port(): void
    {
        $base = 'http://nas:2283';

        $this->assertTrue(ImmichUrl::sameOrigin($base, 'http://nas:2283/api/assets/x/original'));
        $this->assertTrue(ImmichUrl::sameOrigin('https://photos.example.com', 'https://photos.example.com:443/api'));
        $this->assertFalse(ImmichUrl::sameOrigin($base, 'http://nas:2284/api'));
        $this->assertFalse(ImmichUrl::sameOrigin($base, 'https://nas:2283/api'));
        $this->assertFalse(ImmichUrl::sameOrigin($base, 'http://elsewhere:2283/api'));
        $this->assertFalse(ImmichUrl::sameOrigin($base, '/api/relative'));
    }

    #[Test]
    public function asset_ids_must_be_uuids(): void
    {
        $this->assertTrue(AssetId::isValid('0f4d1a3e-6c1b-4f7a-9a51-2b8e3c4d5e6f'));
        $this->assertTrue(AssetId::isValid('0190F4D1-A3E6-7C1B-8F7A-9A512B8E3C4D'));

        $this->assertFalse(AssetId::isValid('../server/about'));
        $this->assertFalse(AssetId::isValid('0f4d1a3e-6c1b-4f7a-9a51-2b8e3c4d5e6f/../x'));
        $this->assertFalse(AssetId::isValid(''));
        $this->assertFalse(AssetId::isValid(null));
        $this->assertFalse(AssetId::isValid(12345));
    }
}
