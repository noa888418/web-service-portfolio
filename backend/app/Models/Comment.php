<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];
    protected $visible = ['id', 'body', 'created_at'];
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['id' => 'string', 'service_request_id' => 'string', 'author_id' => 'string',
            'created_at' => 'immutable_datetime'];
    }
}
