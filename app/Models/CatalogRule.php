<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One rule for where an ECPOS item is filed: by a piece of its code, or by its ECPOS item group. */
class CatalogRule extends Model
{
    public const CODE = 'code_contains';

    public const GROUP = 'group';

    public const TYPES = [self::CODE, self::GROUP];

    public const TYPE_LABELS = [self::CODE => 'Item code contains', self::GROUP => 'ECPOS group is'];

    protected $fillable = ['type', 'match', 'source'];
}
