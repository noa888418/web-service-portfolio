<?php

namespace App\Http;

use App\Models\Comment;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\RequestText;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CommentController
{
    public function index(Request $request, string $request_id)
    {
        $actor = $request->user('sanctum');
        if (! $this->visible($actor, $request_id)) {
            return ApiError::response(404, 'not_found');
        }
        if ($error = AuthInput::check($request, [], ['page'])) {
            return $error;
        }
        $page = $request->query('page', '1');
        if (! is_string($page) || ! preg_match('/\A[1-9][0-9]{0,9}\z/D', $page) || (int) $page > 2147483647) {
            return ApiError::response(422, 'validation_failed', ['page' => ['ページ番号は正の整数で指定してください。']]);
        }
        $page = (int) $page;
        $parent = ServiceRequest::visibleTo($actor)->whereKey($request_id)->select('id');
        // Recheck parent visibility, count and rows in one READ COMMITTED snapshot.
        $rows = DB::select('WITH parent AS MATERIALIZED ('.$parent->toSql().'), scoped AS MATERIALIZED (
                SELECT c.id, c.body, c.created_at, c.author_id, u.display_name AS author_name
                FROM comments c JOIN parent p ON p.id = c.service_request_id JOIN users u ON u.id = c.author_id
            ) SELECT parent.id AS parent_id, totals.total, page.* FROM parent
            CROSS JOIN (SELECT count(*) AS total FROM scoped) totals
            LEFT JOIN (SELECT * FROM scoped ORDER BY created_at ASC, id ASC LIMIT 20 OFFSET ?) page ON true
            ORDER BY page.created_at ASC, page.id ASC', [...$parent->getBindings(), ($page - 1) * 20]);
        if (! $rows) {
            return ApiError::response(404, 'not_found');
        }
        $total = (int) $rows[0]->total;
        $data = [];
        foreach ($rows as $row) {
            if ($row->id !== null) { $data[] = $this->representation($row); }
        }

        return response()->json(['data' => $data, 'meta' => ['current_page' => $page, 'per_page' => 20,
            'total' => $total, 'last_page' => max(1, (int) ceil($total / 20))]]);
    }

    public function store(Request $request, string $request_id)
    {
        $actor = $request->user('sanctum'); // Already FOR SHARE locked by authentication.
        if (! $this->visible($actor, $request_id)) {
            return ApiError::response(404, 'not_found');
        }
        if ($error = AuthInput::check($request, ['body'])) {
            return $error;
        }
        try {
            $body = RequestText::normalize($request->json('body'), 2000, true);
        } catch (InvalidArgumentException) {
            return ApiError::response(422, 'validation_failed', ['body' => ['コメントは1～2000文字で指定してください。']]);
        }
        // The latest parent after waiting decides authorization and completion.
        // No new user locks, version comparison, parent save or independent commit.
        $parent = ServiceRequest::visibleTo($actor)->whereKey($request_id)->lockForUpdate()->first();
        if (! $parent) {
            return ApiError::response(404, 'not_found');
        }
        if ($parent->status === 'completed') {
            return ApiError::response(409, 'request_completed');
        }
        $comment = new Comment;
        $comment->service_request_id = $parent->id;
        $comment->author_id = $actor->id;
        $comment->body = $body;
        $comment->save();

        // Session save and outer commit must succeed before this 201 is sent.
        return response()->json(['data' => $this->representation((object) [
            'id' => $comment->id, 'body' => $comment->body, 'created_at' => $comment->created_at,
            'author_id' => $actor->id, 'author_name' => $actor->display_name,
        ])], 201);
    }

    private function visible(User $actor, string $id): bool
    {
        return preg_match('/\A[1-9][0-9]{0,18}\z/D', $id)
            && (strlen($id) < 19 || strcmp($id, '9223372036854775807') <= 0)
            && ServiceRequest::visibleTo($actor)->whereKey($id)->exists();
    }

    private function representation(object $row): array
    {
        return ['id' => (string) $row->id, 'body' => $row->body,
            'author' => ['id' => (string) $row->author_id, 'display_name' => $row->author_name],
            'created_at' => CarbonImmutable::parse($row->created_at)->utc()->format('Y-m-d\TH:i:s.u\Z')];
    }
}
