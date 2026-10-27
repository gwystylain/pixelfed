<?php

namespace Tests\Unit\Immich;

use App\Immich\Support\PersonName;
use App\Immich\Support\SearchRequest;
use App\Immich\Support\ServerVersion;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fork feature: Immich library. See docs/fork/IMMICH.md
 */
class SearchRequestTest extends TestCase
{
    const ALBUM = '0f4d1a3e-6c1b-4f7a-9a51-2b8e3c4d5e6f';

    #[Test]
    public function servers_from_3_2_get_the_filter_dialect(): void
    {
        $body = SearchRequest::library(ServerVersion::parse('3.3.5'), self::ALBUM, 'c:abc123', 60, false);

        $this->assertSame([
            'filter' => [
                'type' => ['eq' => 'IMAGE'],
                'visibility' => ['eq' => 'timeline'],
                'albumIds' => ['any' => [self::ALBUM]],
            ],
            'orderBy' => ['field' => 'fileCreatedAt', 'direction' => 'desc'],
            'withExif' => true,
            'size' => 60,
            'cursor' => 'abc123',
        ], $body);
    }

    #[Test]
    public function the_filter_dialect_never_carries_a_flat_field(): void
    {
        // Immich 3.2 refuses a body that mixes the two.
        $body = SearchRequest::library(ServerVersion::parse('3.2.0'), null, null, 60, true);

        foreach (['type', 'visibility', 'albumIds', 'page', 'order'] as $flat) {
            $this->assertArrayNotHasKey($flat, $body);
        }

        $this->assertSame(['in' => ['IMAGE', 'VIDEO']], $body['filter']['type']);
        $this->assertArrayNotHasKey('cursor', $body);
    }

    #[Test]
    public function older_servers_get_the_flat_dialect(): void
    {
        $body = SearchRequest::library(ServerVersion::parse('3.1.9'), self::ALBUM, 'p:3', 60, false);

        $this->assertArrayNotHasKey('filter', $body);
        $this->assertArrayNotHasKey('cursor', $body);
        $this->assertSame('IMAGE', $body['type']);
        $this->assertSame('timeline', $body['visibility']);
        $this->assertSame([self::ALBUM], $body['albumIds']);
        $this->assertSame(3, $body['page']);
        $this->assertSame('desc', $body['order']);
    }

    #[Test]
    public function very_old_servers_get_no_visibility_and_an_unknown_one_is_treated_as_old(): void
    {
        $this->assertArrayNotHasKey('visibility', SearchRequest::library(ServerVersion::parse('1.120.0'), null, null, 10, false));
        $this->assertArrayNotHasKey('filter', SearchRequest::library(null, null, null, 10, false));
    }

    #[Test]
    public function smart_search_is_flat_on_every_version(): void
    {
        $body = SearchRequest::smart(ServerVersion::parse('3.3.5'), 'beach at sunset', 'p:2', 60, false);

        $this->assertArrayNotHasKey('filter', $body);
        $this->assertSame('beach at sunset', $body['query']);
        $this->assertSame(2, $body['page']);
        $this->assertSame('IMAGE', $body['type']);
    }

    #[Test]
    public function a_cursor_token_does_not_leak_into_a_flat_page_and_vice_versa(): void
    {
        $this->assertSame(1, SearchRequest::library(ServerVersion::parse('2.0.0'), null, 'c:abc', 10, false)['page']);
        $this->assertArrayNotHasKey('cursor', SearchRequest::library(ServerVersion::parse('3.3.0'), null, 'p:4', 10, false));
    }

    #[Test]
    public function the_next_token_says_which_kind_it_is(): void
    {
        $this->assertSame('c:xyz', SearchRequest::nextToken(['nextCursor' => 'xyz', 'nextPage' => null]));
        $this->assertSame('p:2', SearchRequest::nextToken(['nextPage' => '2']));
        $this->assertNull(SearchRequest::nextToken(['nextPage' => null, 'nextCursor' => null]));
        $this->assertNull(SearchRequest::nextToken([]));
    }

    #[Test]
    public function tokens_are_validated(): void
    {
        $this->assertTrue(SearchRequest::isToken(null));
        $this->assertTrue(SearchRequest::isToken('p:12'));
        $this->assertTrue(SearchRequest::isToken('c:eyJhIjoxfQ=='));
        $this->assertFalse(SearchRequest::isToken('p:0'));
        $this->assertFalse(SearchRequest::isToken('p:-1'));
        $this->assertFalse(SearchRequest::isToken('x:1'));
        $this->assertFalse(SearchRequest::isToken('c:'));
        $this->assertFalse(SearchRequest::isToken("c:a\nb"));
    }

    #[Test]
    public function versions_parse_and_compare(): void
    {
        $this->assertSame('3.3.5', (string) ServerVersion::parse('v3.3.5'));
        $this->assertSame('1.133.0', (string) ServerVersion::parse(['major' => 1, 'minor' => 133, 'patch' => 0]));
        $this->assertNull(ServerVersion::parse('nonsense'));
        $this->assertNull(ServerVersion::parse(['major' => 1]));

        $this->assertTrue(ServerVersion::parse('3.2.0')->atLeast(3, 2));
        $this->assertTrue(ServerVersion::parse('4.0.0')->atLeast(3, 2));
        $this->assertFalse(ServerVersion::parse('3.1.12')->atLeast(3, 2));
        $this->assertTrue(ServerVersion::parse('1.133.1')->atLeast(1, 133));
    }

    #[Test]
    public function person_names_match_on_a_tidied_lower_case_form(): void
    {
        $this->assertSame('ann smith', PersonName::key("  Ann \t Smith "));
        $this->assertSame(PersonName::key('ÉLODIE'), PersonName::key('élodie'));
        $this->assertNotSame(PersonName::key('Ann'), PersonName::key('Ann Smith'));
        $this->assertSame('', PersonName::key('   '));
        $this->assertSame('', PersonName::key(null));
        $this->assertSame('Ann Smith', PersonName::clean("  Ann \t Smith "));
    }
}
