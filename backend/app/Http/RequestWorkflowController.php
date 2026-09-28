<?php

namespace App\Http;

use App\Enums\UserRole;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Policies\ServiceRequestPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class RequestWorkflowController
{
    public function candidates(Request $request, string $request_id)
    {
        $actor = $request->user('sanctum');
        $parent = $this->visible($actor, $request_id);
        if (! $parent) {
            return ApiError::response(404, 'not_found');
        }
        if (! app(ServiceRequestPolicy::class)->manage($actor)) {
            return ApiError::response(403, 'forbidden');
        }
        if ($error = AuthInput::check($request, [], ['page'])) {
            return $error;
        }
        $page = $request->query('page', '1');
        if (! is_string($page) || ! preg_match('/\A[1-9][0-9]{0,9}\z/D', $page) || (int) $page > 2147483647) {
            return ApiError::response(422, 'validation_failed', ['page' => ['ページ番号は正の整数で指定してください。']]);
        }
        // Hold the parent open until session commit; listing candidates locks no other users.
        $parent = ServiceRequest::visibleTo($actor)->whereKey($request_id)->sharedLock()->first();
        if (! $parent) {
            return ApiError::response(404, 'not_found');
        }
        if ($parent->status === 'completed') {
            return ApiError::response(409, 'request_completed');
        }
        $page = (int) $page;
        $rows = DB::select("WITH candidates AS MATERIALIZED (
                SELECT id, display_name FROM users WHERE role = 'it_staff' AND is_active = true
            ) SELECT totals.total, page.* FROM (SELECT count(*) AS total FROM candidates) totals
            LEFT JOIN (SELECT * FROM candidates ORDER BY id ASC LIMIT 20 OFFSET ?) page ON true
            ORDER BY page.id ASC", [($page - 1) * 20]);
        $total = (int) $rows[0]->total;
        $data = [];
        foreach ($rows as $row) {
            if ($row->id !== null) {
                $data[] = ['id' => (string) $row->id, 'display_name' => $row->display_name];
            }
        }

        return response()->json(['data' => $data, 'meta' => ['current_page' => $page, 'per_page' => 20,
            'total' => $total, 'last_page' => max(1, (int) ceil($total / 20))]]);
    }

    public function assignee(Request $request, string $request_id)
    {
        return $this->change($request, $request_id, 'assignee_id');
    }

    public function status(Request $request, string $request_id)
    {
        return $this->change($request, $request_id, 'status');
    }

    private function change(Request $request, string $id, string $field)
    {
        $actor = $request->user('sanctum');
        $before = $this->visible($actor, $id);
        if (! $before) {
            return ApiError::response(404, 'not_found');
        }
        $policy = app(ServiceRequestPolicy::class);
        if (! $policy->manage($actor)) {
            return ApiError::response(403, 'forbidden');
        }
        if ($error = AuthInput::check($request, [$field, 'expected_version'])) {
            return $error;
        }
        $input = $request->json()->all();
        $errors = [];
        $version = $input['expected_version'] ?? null;
        if (! is_int($version) || $version < 1 || $version > 2147483647) {
            $errors['expected_version'] = ['版は1～2147483647の整数で指定してください。'];
        }
        $value = $input[$field] ?? null;
        if ($field === 'assignee_id') {
            if (! array_key_exists($field, $input) || ($value !== null && ! $this->validId($value))) {
                $errors[$field] = ['担当者IDは数字文字列またはnullで指定してください。'];
            }
        } elseif (! in_array($value, ['open', 'in_progress', 'waiting_confirmation', 'completed'], true)) {
            $errors[$field] = ['有効な状態を指定してください。'];
        }
        if ($errors) {
            return ApiError::response(422, 'validation_failed', $errors);
        }

        // Actor FOR SHARE was already taken by authentication. Additional users cannot
        // be globally ordered with that lock: use NOWAIT to avoid a new wait cycle.
        $ids = array_values(array_unique(array_filter([$before->assignee_id, $field === 'assignee_id' ? $value : null], fn ($id) => $id !== null && $id !== $actor->id)));
        usort($ids, fn ($a, $b) => strlen($a) <=> strlen($b) ?: strcmp($a, $b));
        $users = [$actor->id => $actor];
        foreach ($ids as $userId) {
            $users[$userId] = User::whereKey($userId)->lock('for share nowait')->first();
        }
        // One parent per transaction; never acquire another user lock after this row.
        $parent = ServiceRequest::visibleTo($actor)->whereKey($id)->lockForUpdate()->first();
        if (! $parent) {
            return ApiError::response(404, 'not_found');
        }
        if (! $policy->manage($actor)) {
            return ApiError::response(403, 'forbidden');
        }
        if ($parent->version !== $version || $parent->assignee_id !== $before->assignee_id) {
            return ApiError::response(409, 'stale_version');
        }
        if ($parent->status === 'completed') {
            return ApiError::response(409, 'request_completed');
        }
        if ($field === 'assignee_id') {
            if ($value === $parent->assignee_id) {
                return ApiError::response(409, 'no_change');
            }
            if ($value !== null && ! $this->activeIt($users[$value] ?? null)) {
                return ApiError::response(422, 'validation_failed', ['assignee_id' => ['有効なIT担当者を指定してください。']]);
            }
            if ($value === null && $parent->status !== 'open') {
                return ApiError::response(409, 'invalid_assignee_state');
            }
        } else {
            if ($value === $parent->status) {
                return ApiError::response(409, 'no_change');
            }
            $transitions = ['open' => ['in_progress'], 'in_progress' => ['waiting_confirmation'],
                'waiting_confirmation' => ['in_progress', 'completed']];
            if (! in_array($value, $transitions[$parent->status] ?? [], true)) {
                return ApiError::response(409, 'invalid_transition');
            }
            if (! $this->activeIt($users[$parent->assignee_id] ?? null)) {
                return ApiError::response(409, 'invalid_assignee_state');
            }
        }
        if ($parent->version === 2147483647) {
            return ApiError::response(500, 'internal_error'); // Never wrap the version counter.
        }
        $parent->{$field} = $value;
        $parent->version++;
        $parent->save();

        // Session save and the outer transaction commit still follow this response creation.
        return response()->json(['data' => app(ServiceRequestController::class)->detailData($actor, $id)]);
    }

    private function visible(User $actor, string $id): ?ServiceRequest
    {
        return $this->validId($id) ? ServiceRequest::visibleTo($actor)->whereKey($id)->first() : null;
    }

    private function validId(mixed $id): bool
    {
        return is_string($id) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $id)
            && (strlen($id) < 19 || strcmp($id, '9223372036854775807') <= 0);
    }

    private function activeIt(?User $user): bool
    {
        return $user !== null && $user->is_active && $user->role === UserRole::ItStaff;
    }
}
