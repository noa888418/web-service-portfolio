<?php

namespace App\Http;

use App\Models\ServiceRequest;
use App\Models\User;
use App\Policies\ServiceRequestPolicy;
use App\Support\RequestText;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ServiceRequestController
{
    public function store(Request $request, ServiceRequestPolicy $policy)
    {
        $user = $request->user('sanctum'); // Refreshed and FOR SHARE locked by AuthenticatedSession.
        if (! $policy->create($user)) {
            return ApiError::response(403, 'forbidden');
        }
        if ($error = AuthInput::check($request, ['title', 'body', 'category'])) {
            return $error;
        }
        $input = $request->json()->all();
        $normalized = $errors = [];
        foreach (['title' => 100, 'body' => 5000] as $field => $max) {
            try {
                $normalized[$field] = RequestText::normalize($input[$field] ?? null, $max, $field === 'body');
            } catch (InvalidArgumentException) {
                $errors[$field] = [$field === 'title' ? 'タイトルは改行なしの1～100文字で指定してください。' : '内容は1～5000文字で指定してください。'];
            }
        }
        if (! in_array($input['category'] ?? null, ['inquiry', 'bug', 'improvement'], true)) {
            $errors['category'] = ['有効な種別を指定してください。'];
        }
        if ($errors) {
            return ApiError::response(422, 'validation_failed', $errors);
        }

        // No inner commit: the session transaction commits this row and session together.
        $record = new ServiceRequest;
        $record->requester_id = $user->id;
        $record->title = $normalized['title'];
        $record->body = $normalized['body'];
        $record->category = $input['category'];
        $record->status = 'open';
        $record->assignee_id = null;
        $record->version = 1;
        $record->save();
        $row = $this->query($user, true)->where('service_requests.id', $record->id)->first();

        return response()->json(['data' => $this->representation($row, true)], 201,
            ['Location' => '/api/requests/'.$record->id]);
    }

    public function index(Request $request)
    {
        if ($error = AuthInput::check($request, [], ['page'])) {
            return $error;
        }
        $page = $request->query('page', '1');
        if (! is_string($page) || ! preg_match('/\A[1-9][0-9]{0,9}\z/D', $page) || (int) $page > 2147483647) {
            return ApiError::response(422, 'validation_failed', ['page' => ['ページ番号は正の整数で指定してください。']]);
        }
        $page = (int) $page;
        $query = $this->query($request->user('sanctum'), false);
        // A single READ COMMITTED statement gives count and rows the same snapshot,
        // including an empty/out-of-range page. Do not change isolation after auth SQL.
        $rows = DB::select('WITH scoped AS MATERIALIZED ('.$query->toSql().')
            SELECT totals.total, page.* FROM (SELECT count(*) AS total FROM scoped) totals
            LEFT JOIN (SELECT * FROM scoped ORDER BY created_at DESC, id DESC LIMIT 20 OFFSET ?) page ON true
            ORDER BY page.created_at DESC, page.id DESC', [...$query->getBindings(), ($page - 1) * 20]);
        $total = (int) $rows[0]->total;
        $data = [];
        foreach ($rows as $row) {
            if ($row->id !== null) {
                $data[] = $this->representation($row, false);
            }
        }

        return response()->json(['data' => $data, 'meta' => ['current_page' => $page, 'per_page' => 20,
            'total' => $total, 'last_page' => max(1, (int) ceil($total / 20))]]);
    }

    public function show(Request $request, string $request_id)
    {
        if (! preg_match('/\A[1-9][0-9]{0,18}\z/D', $request_id)
            || (strlen($request_id) === 19 && strcmp($request_id, '9223372036854775807') > 0)) {
            return ApiError::response(404, 'not_found');
        }
        // Scope before input checks: forbidden and absent IDs have identical responses.
        $row = $this->query($request->user('sanctum'), true)->where('service_requests.id', $request_id)->first();
        if (! $row) {
            return ApiError::response(404, 'not_found');
        }
        if ($error = AuthInput::check($request)) {
            return $error;
        }

        return response()->json(['data' => $this->representation($row, true)]);
    }

    public function detailData(User $user, string $id): array
    {
        return $this->representation($this->query($user, true)->where('service_requests.id', $id)->first(), true);
    }

    private function query(User $user, bool $detail)
    {
        $columns = ['service_requests.id', 'title', 'category', 'status', 'version', 'service_requests.created_at',
            'requester_id', 'requester.display_name as requester_name', 'assignee_id', 'assignee.display_name as assignee_name'];
        if ($detail) {
            array_push($columns, 'body', 'service_requests.updated_at');
        }

        return ServiceRequest::query()->visibleTo($user)
            ->join('users as requester', 'requester.id', '=', 'service_requests.requester_id')
            ->leftJoin('users as assignee', 'assignee.id', '=', 'service_requests.assignee_id')
            ->select($columns)->toBase();
    }

    private function representation(object $row, bool $detail): array
    {
        $data = ['id' => (string) $row->id, 'title' => $row->title, 'category' => $row->category,
            'requester' => ['id' => (string) $row->requester_id, 'display_name' => $row->requester_name],
            'assignee' => $row->assignee_id === null ? null : ['id' => (string) $row->assignee_id, 'display_name' => $row->assignee_name],
            'status' => $row->status, 'version' => (int) $row->version,
            'created_at' => CarbonImmutable::parse($row->created_at)->utc()->format('Y-m-d\TH:i:s.u\Z')];
        if ($detail) {
            $data['body'] = $row->body;
            $data['updated_at'] = CarbonImmutable::parse($row->updated_at)->utc()->format('Y-m-d\TH:i:s.u\Z');
        }

        return $data;
    }
}
