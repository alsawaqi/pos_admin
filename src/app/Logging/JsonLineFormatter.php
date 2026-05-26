<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * One-line JSON formatter tuned for log-shipping pipelines
 * (Loki, Datadog, CloudWatch, etc.). One record → one line → one
 * JSON document — no nested {context,extra} envelope to flatten
 * downstream.
 *
 * Promotes the processor-supplied `extra.trace_id` /
 * `extra.user_id` etc. to top-level keys so downstream filters
 * like `{user_id="42"}` work without a JSON-path expression.
 *
 * Reserved Monolog fields (message, level_name, channel, datetime)
 * are also promoted to top level. `context` is preserved as a
 * sub-object so Log::info('msg', ['key' => 'val']) call sites
 * keep working.
 *
 * Wired in {@see config/logging.php} on the `json` channel.
 */
class JsonLineFormatter implements FormatterInterface
{
    public function format(LogRecord $record): string
    {
        $payload = [
            'timestamp' => $record->datetime->format('Y-m-d\TH:i:s.uP'),
            'level' => $record->level->getName(),
            'channel' => $record->channel,
            'message' => $record->message,
        ];

        // Promote known cross-cutting fields produced by
        // {@see AppContextProcessor} to top level so a log query
        // like `user_id=42 method=POST` doesn't need a JSON-path.
        $promote = [
            'trace_id', 'request_id', 'user_id', 'company_id',
            'method', 'path', 'route', 'ip',
            'command', 'pid',
        ];
        foreach ($promote as $key) {
            if (array_key_exists($key, $record->extra)) {
                $payload[$key] = $record->extra[$key];
            }
        }

        // Anything else processors added stays nested under `extra`
        // so the top-level shape stays predictable. Skip when empty
        // so we don't ship empty objects on every line.
        $leftoverExtra = array_diff_key($record->extra, array_flip($promote));
        if ($leftoverExtra !== []) {
            $payload['extra'] = $leftoverExtra;
        }

        // Caller-supplied context (Log::info('x', $ctx)) is kept
        // as a nested object — different semantic than processor
        // output (which describes the request) so it deserves its
        // own bucket.
        if ($record->context !== []) {
            $payload['context'] = $record->context;
        }

        // JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE keeps
        // Arabic text + URL paths readable in `tail -f`.
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        // Final newline so the output is one valid JSON document
        // per line (NDJSON / JSONL).
        return ($json !== false ? $json : '{"level":"ERROR","message":"log formatter encode failed"}')."\n";
    }

    /**
     * @param  array<int, LogRecord>  $records
     */
    public function formatBatch(array $records): string
    {
        $out = '';
        foreach ($records as $record) {
            $out .= $this->format($record);
        }

        return $out;
    }
}
