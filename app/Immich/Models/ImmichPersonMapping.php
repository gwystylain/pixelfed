<?php

namespace App\Immich\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fork feature: Immich library. See docs/fork/IMMICH.md
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $name_key
 * @property int $profile_id
 */
class ImmichPersonMapping extends Model
{
    protected $table = 'immich_person_mappings';

    protected $fillable = [
        'user_id',
        'name',
        'name_key',
        'profile_id',
    ];
}
