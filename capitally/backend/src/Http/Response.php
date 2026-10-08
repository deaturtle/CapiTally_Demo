<?php
declare(strict_types=1);

namespace CapiTally\Http;

final class Response
{
    public static function ok(mixed $data, int $status = 200): never
    {
        self::send($status, ['ok' => true, 'data' => $data]);
    }

    public static function error(int $status, string $code, string $message, array $details = []): never
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }
        self::send($status, ['ok' => false, 'error' => $error]);
    }

    /** Streams a CSV download (UTF-8 with BOM for Excel). Cells that could run as spreadsheet formulas are neutralised. */
    public static function csv(string $filename, array $header, iterable $rows): never
    {
        http_response_code(200);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        $clean = static fn ($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !is_numeric($v) ? "'" . $v : $v;
        fputcsv($out, $header);
        foreach ($rows as $row) {
            fputcsv($out, array_map($clean, array_values($row)));
        }
        fclose($out);
        exit;
    }

    private static function send(int $status, array $payload): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }
}
