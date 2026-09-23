<?php

namespace Storyfeed\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A stand-in for a vendor model the app can't edit (a media library's
 * `Media`): no Feedable, no trait. Tests make it Feedable with
 * Storyfeed::feedable().
 *
 * @property int $id
 * @property string $file_name
 */
class Photo extends Model
{
    use SoftDeletes;

    protected $guarded = [];
}
