<?php

namespace App\Immich\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Fork feature: Immich library. See docs/fork/IMMICH.md
 *
 * @property int $id
 * @property int $user_id
 * @property string $base_url
 * @property string $api_key
 * @property string|null $server_version
 * @property string|null $immich_user_name
 * @property string|null $immich_user_email
 * @property array<string, bool>|null $permissions
 * @property Carbon|null $verified_at
 */
class ImmichConnection extends Model
{
    protected $table = 'immich_connections';

    protected $fillable = [
        'user_id',
        'base_url',
        'api_key',
        'server_version',
        'immich_user_name',
        'immich_user_email',
        'permissions',
        'verified_at',
    ];

    // Never serialised: nothing should be able to hand the key back out.
    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'permissions' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * The last four characters, so the settings page can show which key is
     * saved without showing the key.
     */
    public function keyHint(): string
    {
        return '…'.substr((string) $this->api_key, -4);
    }
}
