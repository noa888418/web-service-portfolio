<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ServiceRequest extends Model
{
    protected $guarded = ['*'];
    protected $visible = ['id', 'title', 'body', 'category', 'status', 'version', 'created_at', 'updated_at'];
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['id' => 'string', 'requester_id' => 'string', 'assignee_id' => 'string',
            'version' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->is_active) {
            return $query->whereRaw('false');
        }

        return match ($user->role) {
            UserRole::ItStaff => $query,
            UserRole::Employee => $query->where('service_requests.requester_id', $user->id),
            default => $query->whereRaw('false'),
        };
    }
}
