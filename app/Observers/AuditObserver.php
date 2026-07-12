<?php

namespace App\Observers;

use App\Services\ActivityLogService;
use Illuminate\Database\Eloquent\Model;

class AuditObserver
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
    ) {
    }

    public function created(Model $model): void
    {
        $this->activityLogService->recordCreated($model);
    }

    public function updated(Model $model): void
    {
        $this->activityLogService->recordUpdated($model);
    }

    public function deleted(Model $model): void
    {
        $this->activityLogService->recordDeleted($model);
    }
}
