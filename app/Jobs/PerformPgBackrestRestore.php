<?php

namespace App\Jobs;

use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PerformPgBackrestRestore implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 hour default timeout for restores

    public function __construct(
        public ScheduledDatabaseBackup $backup,
        public ScheduledDatabaseBackupExecution $execution,
        public ?string $targetTime = null
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        try {
            $this->execution->update([
                'status' => 'running',
                'message' => 'Restore initiated. Database service stopped.',
            ]);

            \App\Services\Backup\PgBackrestService::restoreWithDowntime(
                $this->backup,
                $this->targetTime,
                $this->timeout
            );

            $this->execution->update([
                'status' => 'success',
                'message' => $this->execution->message . "\n[Success] Restore completed at " . now(),
            ]);

        } catch (Throwable $e) {
            $this->execution->update([
                'message' => $this->execution->message . "\n[Restore Failed] " . $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        // Notify team via UI or Channels (simplified for this iteration)
        // In a full implementation, we'd fire a Notification here.
    }
}
