<?php

namespace App\Immich\Services;

use App\Immich\Models\ImmichPersonMapping;
use App\Immich\Support\AssetId;
use App\Immich\Support\PersonName;
use App\Models\Profile;
use App\Models\User;
use App\Services\UserFilterService;

/**
 * Fork feature: Immich library. See docs/fork/IMMICH.md
 *
 * Turns the people Immich recognised into Pixelfed accounts to tag, through
 * the account's own name mappings.
 *
 * Profiles come back in exactly the shape upstream's tag search returns —
 * `{id, name, privacy, avatar}` — so the composer can push a suggestion
 * straight into its `taggedUsernames`, and the publish endpoint reads it
 * as if the author had searched for it.
 */
class ImmichPeople
{
    /**
     * The named, visible people in an asset, each with the account they are
     * mapped to or null. One entry per name.
     *
     * @param  array<int, mixed>  $people  An Immich asset's `people`
     * @return array<int, array{name: string, key: string, person_id: string|null, profile: array<string, mixed>|null}>
     */
    public function suggestionsFor(User $user, array $people): array
    {
        $byKey = $this->distinctNames($people);

        if (empty($byKey)) {
            return [];
        }

        $mappings = ImmichPersonMapping::whereUserId($user->id)
            ->whereIn('name_key', array_keys($byKey))
            ->get();

        $profiles = $this->profiles($user, $mappings->pluck('profile_id')->all());

        foreach ($mappings as $mapping) {
            if (isset($profiles[$mapping->profile_id])) {
                $byKey[$mapping->name_key]['profile'] = $profiles[$mapping->profile_id];
            }
        }

        return array_values($byKey);
    }

    /**
     * @param  array<int, mixed>  $people
     * @return array<string, array{name: string, key: string, person_id: string|null, profile: null}>
     */
    public function distinctNames(array $people): array
    {
        $byKey = [];

        foreach ($people as $person) {
            if (! is_array($person) || ! empty($person['isHidden'])) {
                continue;
            }

            $name = PersonName::clean($person['name'] ?? '');
            $key = PersonName::key($name);

            // Unnamed faces have nothing to map from.
            if ($key === '' || isset($byKey[$key])) {
                continue;
            }

            $byKey[$key] = [
                'name' => $name,
                'key' => $key,
                'person_id' => AssetId::isValid($person['id'] ?? null) ? $person['id'] : null,
                'profile' => null,
            ];
        }

        return $byKey;
    }

    /**
     * Accounts this user may tag, keyed by id, in the tag-search shape.
     * The same exclusions as upstream's tag search: anyone who has blocked
     * the user, and the user themselves.
     *
     * @param  array<int, int|string>  $ids
     * @return array<int, array{id: string, name: string, privacy: bool, avatar: string}>
     */
    public function profiles(User $user, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if (empty($ids)) {
            return [];
        }

        $excluded = UserFilterService::searchExcludedProfileIds($user->profile_id)
            ->map(fn ($id) => (int) $id)
            ->all();

        return Profile::whereIn('id', $ids)
            ->whereNull('status')
            ->get()
            ->reject(fn (Profile $p) => in_array((int) $p->id, $excluded, true))
            ->mapWithKeys(fn (Profile $p) => [(int) $p->id => self::present($p)])
            ->all();
    }

    /**
     * @return array{id: string, name: string, privacy: bool, avatar: string}
     */
    public static function present(Profile $profile): array
    {
        return [
            'id' => (string) $profile->id,
            'name' => $profile->username,
            'privacy' => true,
            'avatar' => $profile->avatarUrl(),
        ];
    }
}
