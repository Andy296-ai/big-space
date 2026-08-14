<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use Illuminate\Console\Command;

/**
 * Первая scheduled-команда в проекте — см. bootstrap/app.php::withSchedule().
 * Безопасный no-op, пока config('activity_log.retention_days') не задан
 * (значение по умолчанию — хранить лог бессрочно).
 */
class PruneActivityLog extends Command
{
    protected $signature = 'activity-log:prune';

    protected $description = 'Delete activity log rows older than the configured retention period';

    public function handle(): int
    {
        $days = config('activity_log.retention_days');

        if ($days === null) {
            $this->info('Retention is disabled (ACTIVITY_LOG_RETENTION_DAYS not set) — nothing pruned.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays((int) $days);

        $deleted = ActivityLog::where('created_at', '<', $cutoff)->delete();

        if ($deleted > 0) {
            ActivityLog::record(
                actor: null,
                action: ActivityLog::ACTION_LOG_PRUNED,
                meta: ['deleted_count' => $deleted, 'cutoff' => $cutoff->toDateTimeString()],
            );
        }

        $this->info("Pruned {$deleted} activity log row(s) older than {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
