<?php declare(strict_types=1);

namespace Bref\Monolog;

use ArrayObject;
use DateTimeInterface;
use JsonSerializable;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;
use Stringable;
use Throwable;

/**
 * Monolog formatter optimized for CloudWatch logs.
 *
 * @phpstan-type Monolog2Record array{message: string, level_name: string, context: array<mixed>, extra: array<mixed>}
 */
class CloudWatchFormatter extends NormalizerFormatter
{
    /**
     * @param LogRecord|Monolog2Record $record
     */
    public function format(LogRecord|array $record): string
    {
        $fields = $this->fields($record);
        $message = str_replace(["\r\n", "\r", "\n"], ' ', $fields['message']);
        $json = $this->toJson($this->normalizeRecord($record), true);
        $line = "{$fields['level']}\t$message\t$json\n";

        // Bref sets the ID of the current Lambda invocation. Lambda's own runtimes start their lines with it:
        // CloudWatch Logs Insights reads it as `@requestId`, like in Lambda's START, END and REPORT lines.
        $requestId = $_SERVER['LAMBDA_REQUEST_ID'] ?? null;
        if (is_string($requestId) && $requestId !== '') {
            return "$requestId\t$line";
        }

        return $line;
    }

    /**
     * @param array<LogRecord|Monolog2Record> $records
     */
    public function formatBatch(array $records): string
    {
        return implode('', array_map(fn ($record) => $this->format($record), $records));
    }

    /**
     * @param LogRecord|Monolog2Record $record
     * @return array<mixed>
     */
    protected function normalizeRecord(LogRecord|array $record): array
    {
        $fields = $this->fields($record);
        $data = [
            'message' => $fields['message'],
            'level' => $fields['level'],
        ];
        $context = $fields['context'];
        // Move any exception to the root
        $exception = $context['exception'] ?? null;
        if ($exception instanceof Throwable) {
            $data['exception'] = $exception;
            unset($context['exception']);
        }
        if ($context !== []) {
            $data['context'] = $context;
        }
        if ($fields['extra'] !== []) {
            $data['extra'] = $fields['extra'];
        }

        /** @var array<mixed> $normalized */
        $normalized = $this->normalize($data);

        return $normalized;
    }

    /**
     * Monolog 3 records are objects, Monolog 2 records are arrays.
     *
     * @param LogRecord|Monolog2Record $record
     * @return array{message: string, level: string, context: array<mixed>, extra: array<mixed>}
     */
    private function fields(LogRecord|array $record): array
    {
        if ($record instanceof LogRecord) {
            return [
                'message' => $record->message,
                'level' => $record->level->getName(),
                'context' => $record->context,
                'extra' => $record->extra,
            ];
        }

        return [
            'message' => $record['message'],
            'level' => $record['level_name'],
            'context' => $record['context'],
            'extra' => $record['extra'],
        ];
    }

    /**
     * @return scalar|array<mixed>|object|null
     */
    protected function normalize(mixed $data, int $depth = 0): mixed
    {
        if ($depth > $this->maxNormalizeDepth) {
            return 'Over ' . $this->maxNormalizeDepth . ' levels deep, aborting normalization';
        }

        if (is_array($data)) {
            $normalized = [];

            $count = 1;
            foreach ($data as $key => $value) {
                if ($count++ > $this->maxNormalizeItemCount) {
                    $normalized['...'] = 'Over ' . $this->maxNormalizeItemCount . ' items (' . count($data) . ' total), aborting normalization';
                    break;
                }

                $normalized[$key] = $this->normalize($value, $depth + 1);
            }

            return $normalized;
        }

        if (is_object($data)) {
            if ($data instanceof DateTimeInterface) {
                return $this->formatDate($data);
            }

            if ($data instanceof Throwable) {
                return $this->normalizeException($data, $depth);
            }

            // if the object has specific json serializability we want to make sure we skip the __toString treatment below
            if ($data instanceof JsonSerializable) {
                return $data;
            }

            if ($data instanceof Stringable) {
                return $data->__toString();
            }

            if (get_class($data) === '__PHP_Incomplete_Class') {
                return new ArrayObject($data);
            }

            return $data;
        }

        if (is_scalar($data) || $data === null) {
            return $data;
        }

        // Resources
        return parent::normalize($data);
    }
}
