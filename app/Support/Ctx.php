<?php
declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PDO;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Per-request context for the study API: database, session and response builders.
 */
final class Ctx
{
    public static PDO $db;
    public static SessionBag $S;
    public static Request $req;

    /** Headers the first version sent on every API response. */
    private const HEADERS = [
        'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer',
    ];

    public static function boot(Request $request): void
    {
        self::$req = $request;
        self::$S = new SessionBag($request);
        $pdo = DB::connection()->getPdo();
        // Same behaviour as the original script: array rows, real prepared statements, exceptions.
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("SET time_zone = '+00:00'");
        self::$db = $pdo;
    }

    /** Byte-identical to the original: plain json_encode, no pretty printing or extra escaping. */
    public static function json($data, int $status = 200): Response
    {
        return new Response((string) json_encode($data), $status, ['Content-Type' => 'application/json'] + self::HEADERS);
    }

    public static function plain(string $body, int $status): Response
    {
        return new Response($body, $status, self::HEADERS);
    }

    public static function download(string $csv, string $filename): Response
    {
        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ] + self::HEADERS);
    }

    public static function stream(callable $callback, int $status, array $headers): StreamedResponse
    {
        return new StreamedResponse($callback, $status, $headers + self::HEADERS);
    }
}
