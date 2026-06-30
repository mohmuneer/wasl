<?php
/**
 * Response — استجابة JSON موحّدة لـ API وَصْل
 *
 * الاستخدام:
 *  Response::success(['tickets' => $data], 'تم بنجاح');
 *  Response::error('غير مصرح', 401);
 *  Response::paginated($db->paginate($sql, $params, $page));
 */
class Response
{
    public static function success(mixed $data = null, string $message = 'success', int $code = 200): never
    {
        self::send([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    public static function error(string $message, int $code = 400, mixed $errors = null): never
    {
        $body = [
            'success' => false,
            'message' => $message,
        ];
        if ($errors !== null) $body['errors'] = $errors;
        self::send($body, $code);
    }

    public static function paginated(array $page, string $message = 'success'): never
    {
        self::send([
            'success'  => true,
            'message'  => $message,
            'data'     => $page['data'],
            'meta'     => [
                'total'   => $page['total'],
                'pages'   => $page['pages'],
                'page'    => $page['page'],
                'limit'   => $page['limit'],
            ],
        ], 200);
    }

    private static function send(array $body, int $code): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache');

        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}
