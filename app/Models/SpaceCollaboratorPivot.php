<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $space_id
 * @property int $user_id
 * @property string $role
 */
class SpaceCollaboratorPivot extends Pivot
{
    protected $table = 'space_collaborators';
}
