<?php

namespace App\Http;

final class ApiError
{
    public static function response(int $status, string $code, ?array $fields = null, array $headers = [])
    {
        $message = match ($status) {
            401 => $code === 'invalid_credentials' ? '認証に失敗しました。' : 'ログインが必要です。',
            403 => 'この操作は許可されていません。',
            404 => '対象が見つかりません。',
            419 => '認証情報を再取得してください。',
            422 => '入力内容を確認してください。',
            429 => '時間をおいて再試行してください。',
            default => '要求を処理できませんでした。',
        };
        $error = ['code' => $code, 'message' => $message];
        if ($status === 422) {
            $error['fields'] = $fields ?? new \stdClass;
        }

        return response()->json(['error' => $error], $status, $headers + ['Cache-Control' => 'private, no-store']);
    }
}
