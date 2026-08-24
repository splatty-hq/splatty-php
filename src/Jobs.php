<?php

declare(strict_types=1);

namespace Splatty;

/**
 * Queue-agnostic helpers for reporting background job failures. PHP has no
 * single dominant queue, so instead of per-backend adapters you map whatever
 * your queue gives you onto a job array.
 */
final class Jobs
{
    public const MAX_ARGS_LENGTH = 2048;

    /**
     * Serializes job arguments for the job_args extra, truncating anything that
     * would bloat the event. Returns null when there is nothing to attach.
     */
    public static function encodeArgs(mixed $args): ?string
    {
        if ($args === null) {
            return null;
        }

        $encoded = json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return null;
        }
        if (strlen($encoded) <= self::MAX_ARGS_LENGTH) {
            return $encoded;
        }

        return substr($encoded, 0, self::MAX_ARGS_LENGTH) . '...(truncated)';
    }

    /**
     * Builds the scope a job failure is reported with.
     *
     * Recognised keys: backend, jobClass, queue, jobId, attempts, args, extra.
     *
     * @param array<string, mixed> $job
     *
     * @return array<string, mixed>
     */
    public static function scope(array $job): array
    {
        $tags = [];
        if (!empty($job['backend'])) {
            $tags['job_backend'] = (string) $job['backend'];
        }
        if (!empty($job['jobClass'])) {
            $tags['job_class'] = (string) $job['jobClass'];
        }
        if (!empty($job['queue'])) {
            $tags['job_queue'] = (string) $job['queue'];
        }

        $extra = is_array($job['extra'] ?? null) ? $job['extra'] : [];
        if (isset($job['jobId']) && $job['jobId'] !== '') {
            $extra['job_id'] = (string) $job['jobId'];
        }
        if (isset($job['attempts']) && is_numeric($job['attempts'])) {
            $extra['job_attempts'] = (int) $job['attempts'];
        }
        $args = self::encodeArgs($job['args'] ?? null);
        if ($args !== null) {
            $extra['job_args'] = $args;
        }

        $scope = ['tags' => $tags, 'extra' => $extra];
        if (!empty($job['jobClass'])) {
            $scope['transaction'] = (string) $job['jobClass'];
        }

        return $scope;
    }
}
