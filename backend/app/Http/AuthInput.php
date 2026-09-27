<?php

namespace App\Http;

use Illuminate\Http\Request;

final class AuthInput
{
    public static function check(Request $request, array $allowed = [])
    {
        $protected = ['id', 'requester_id', 'author_id', 'service_request_id', 'assignee_id', 'assignee_role', 'status', 'role', 'is_active', 'auth_version', 'password', 'created_at', 'updated_at', 'version'];
        $extra = array_merge(array_diff(array_keys($request->json()->all()), $allowed), array_keys($request->query()));
        if (array_intersect($extra, $protected)) {
            return ApiError::response(403, 'forbidden');
        }
        if ($extra) {
            return ApiError::response(422, 'validation_failed');
        }

        return null;
    }
}
