<?php

namespace App\Immich\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Immich\Http\Controllers\Concerns\UsesImmichConnection;
use App\Immich\Models\ImmichConnection;
use App\Immich\Models\ImmichPersonMapping;
use App\Immich\Services\ImmichClient;
use App\Immich\Services\ImmichException;
use App\Immich\Services\ImmichPeople;
use App\Immich\Support\AssetId;
use App\Immich\Support\ImmichUrl;
use App\Immich\Support\PersonName;
use Illuminate\Http\Request;

/**
 * Fork feature: Immich library. See docs/fork/IMMICH.md
 *
 * Settings → Immich: the connection, and which Immich names are which
 * Pixelfed accounts.
 */
class ImmichSettingsController extends Controller
{
    use UsesImmichConnection;

    /** Without these the picker cannot list, show or fetch a photo. */
    const REQUIRED_PERMISSIONS = ['asset.read', 'asset.view', 'asset.download'];

    /** Each of these switches off one thing, and says which. */
    const OPTIONAL_PERMISSIONS = ['person.read', 'album.read', 'user.read'];

    public function __construct(protected ImmichPeople $people)
    {
        $this->middleware('auth');
    }

    public function index(Request $request)
    {
        abort_unless(config('immich.enabled'), 404);

        return view('immich.settings', [
            'immichConfig' => [
                'default_url' => (string) config('immich.default_url'),
                'host_restricted' => ! empty(config('immich.allowed_hosts')),
                'required' => self::REQUIRED_PERMISSIONS,
                'optional' => self::OPTIONAL_PERMISSIONS,
            ],
        ]);
    }

    public function show(Request $request)
    {
        abort_unless(config('immich.enabled'), 404);
        abort_if(! $request->user(), 403);

        return response()->json($this->present(
            ImmichConnection::whereUserId($request->user()->id)->first()
        ));
    }

    /**
     * Connect, or change the address or key. The key is checked against the
     * server before anything is saved; a key that is valid but short of
     * permissions is saved anyway, with the gaps listed, so fixing it in
     * Immich only needs "Check again" rather than pasting it a second time.
     */
    public function update(Request $request)
    {
        abort_unless(config('immich.enabled'), 404);
        abort_if(! $request->user(), 403);

        $this->validate($request, [
            'url' => 'required|string|max:500',
            'api_key' => 'nullable|string|min:8|max:200',
        ]);

        $url = ImmichUrl::normalize($request->input('url'));

        if (! $url) {
            return $this->invalid('url', 'That does not look like a server address. Use the one you open Immich at, e.g. http://192.168.1.10:2283');
        }

        if (! ImmichUrl::hostAllowed($url, (array) config('immich.allowed_hosts'))) {
            return $this->invalid('url', 'This instance only allows connecting to particular Immich servers, and that is not one of them.');
        }

        $existing = ImmichConnection::whereUserId($request->user()->id)->first();
        $key = trim((string) $request->input('api_key'));

        if ($key === '') {
            if (! $existing) {
                return $this->invalid('api_key', 'Paste the API key you created in Immich.');
            }

            $key = $existing->api_key;
        }

        try {
            $probe = (new ImmichClient($url, $key))->probe();
        } catch (ImmichException $e) {
            return $this->invalid($e->kind === ImmichException::UNAUTHORIZED ? 'api_key' : 'url', $e->getMessage());
        }

        $connection = $existing ?? new ImmichConnection(['user_id' => $request->user()->id]);
        $connection->base_url = $url;
        $connection->api_key = $key;
        $this->record($connection, $probe);
        $connection->save();

        return response()->json($this->present($connection));
    }

    /**
     * Re-run the permission check with the saved key.
     */
    public function check(Request $request)
    {
        $connection = $this->connection($request);

        try {
            $probe = ImmichClient::for($connection)->probe();
        } catch (ImmichException $e) {
            return $this->immichError($e);
        }

        $this->record($connection, $probe);
        $connection->save();

        return response()->json($this->present($connection));
    }

    /**
     * Forget the connection. Name mappings are kept: they are this
     * account's, not the server's, and reconnecting should not mean
     * redoing them.
     */
    public function destroy(Request $request)
    {
        abort_unless(config('immich.enabled'), 404);
        abort_if(! $request->user(), 403);

        ImmichConnection::whereUserId($request->user()->id)->delete();

        return response()->json($this->present(null));
    }

    /**
     * Every named person in Immich, with the account each is mapped to, and
     * the mappings whose name Immich no longer has.
     *
     * Still answers when Immich does not, with `error` set, so mappings can
     * be seen and removed while the server is down.
     */
    public function people(Request $request)
    {
        abort_unless(config('immich.enabled'), 404);
        abort_if(! $request->user(), 403);

        $user = $request->user();
        $connection = ImmichConnection::whereUserId($user->id)->first();
        $error = null;
        $names = [];

        if ($connection) {
            try {
                $names = $this->people->distinctNames(ImmichClient::for($connection)->people());
            } catch (ImmichException $e) {
                $error = $e->getMessage();
            }
        }

        $mappings = ImmichPersonMapping::whereUserId($user->id)->orderBy('name')->get();
        $profiles = $this->people->profiles($user, $mappings->pluck('profile_id')->all());

        $people = [];
        foreach ($names as $key => $person) {
            $people[$key] = [
                'name' => $person['name'],
                'key' => $key,
                'thumbnail' => $person['person_id'] ? '/api/immich/v1/people/'.$person['person_id'].'/thumbnail' : null,
                'mapping' => null,
            ];
        }

        $unmatched = [];
        foreach ($mappings as $mapping) {
            $presented = $this->presentMapping($mapping, $profiles);

            if (isset($people[$mapping->name_key])) {
                $people[$mapping->name_key]['mapping'] = $presented;
            } else {
                $unmatched[] = $presented;
            }
        }

        $people = array_values($people);
        usort($people, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return response()->json([
            'connected' => (bool) $connection,
            'error' => $error,
            'people' => $people,
            'unmatched' => $unmatched,
        ]);
    }

    public function personThumbnail(Request $request, string $id)
    {
        $connection = $this->connection($request);
        abort_unless(AssetId::isValid($id), 404);

        try {
            $file = ImmichClient::for($connection)->small('people/'.$id.'/thumbnail');
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
     * Map a name to an account, replacing any mapping that name had.
     */
    public function saveMapping(Request $request)
    {
        abort_unless(config('immich.enabled'), 404);
        abort_if(! $request->user(), 403);

        $this->validate($request, [
            'name' => 'required|string|max:'.PersonName::MAX_LENGTH,
            'profile_id' => 'required|integer|min:1',
        ]);

        $user = $request->user();
        $name = PersonName::clean($request->input('name'));
        $key = PersonName::key($name);

        if ($key === '') {
            return $this->invalid('name', 'Enter the name as it appears in Immich.');
        }

        $profiles = $this->people->profiles($user, [(int) $request->input('profile_id')]);

        if (empty($profiles)) {
            return $this->invalid('profile_id', 'That account cannot be tagged.');
        }

        $mapping = ImmichPersonMapping::updateOrCreate(
            ['user_id' => $user->id, 'name_key' => $key],
            ['name' => $name, 'profile_id' => (int) $request->input('profile_id')]
        );

        return response()->json($this->presentMapping($mapping, $profiles));
    }

    public function deleteMapping(Request $request, string $id)
    {
        abort_unless(config('immich.enabled'), 404);
        abort_if(! $request->user(), 403);
        abort_unless(ctype_digit($id), 404);

        ImmichPersonMapping::whereUserId($request->user()->id)->whereKey((int) $id)->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * @param  array{version: mixed, user: array|null, permissions: array<string, bool|null>}  $probe
     */
    protected function record(ImmichConnection $connection, array $probe): void
    {
        $connection->server_version = $probe['version'] ? (string) $probe['version'] : null;
        $connection->immich_user_name = $probe['user']['name'] ?? null;
        $connection->immich_user_email = $probe['user']['email'] ?? null;
        $connection->permissions = $probe['permissions'];
        $connection->verified_at = now();
    }

    protected function present(?ImmichConnection $connection): array
    {
        if (! $connection) {
            return ['connected' => false];
        }

        $permissions = (array) $connection->permissions;
        $missing = array_values(array_filter(
            self::REQUIRED_PERMISSIONS,
            fn ($p) => ($permissions[$p] ?? null) === false
        ));

        return [
            'connected' => true,
            'url' => $connection->base_url,
            'key_hint' => $connection->keyHint(),
            'server_version' => $connection->server_version,
            'user' => [
                'name' => $connection->immich_user_name,
                'email' => $connection->immich_user_email,
            ],
            'permissions' => $permissions,
            'missing_required' => $missing,
            'verified_at' => $connection->verified_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $profiles
     */
    protected function presentMapping(ImmichPersonMapping $mapping, array $profiles): array
    {
        return [
            'id' => (string) $mapping->id,
            'name' => $mapping->name,
            'key' => $mapping->name_key,
            // Null when the account has since gone or blocked this user.
            'profile' => $profiles[$mapping->profile_id] ?? null,
        ];
    }

    protected function invalid(string $field, string $message)
    {
        return response()->json([
            'message' => $message,
            'errors' => [$field => [$message]],
        ], 422);
    }
}
