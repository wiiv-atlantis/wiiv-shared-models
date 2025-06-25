<?php

namespace Wiiv\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class TenantUsage extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'tenant_usage';

    protected $fillable = [
        'tenant_id',
        'requests_count',
        'last_reset',
        'quota_limit',
        'usage_date',
    ];

    protected $casts = [
        'last_reset' => 'datetime',
        'usage_date' => 'date',
    ];

    // Relations
    public function tenant(): BelongsTo
    {
        // This will be resolved by each project's own Tenant model
        $tenantModel = config('wiiv-shared.tenant_model', 'App\\Models\\Tenant');
        return $this->belongsTo($tenantModel, 'tenant_id', 'id');
    }

    // Usage Tracking Methods
    public static function incrementUsage(string $tenantId, int $quotaLimit = 50000): self
    {
        $today = Carbon::today();
        
        $usage = self::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'usage_date' => $today,
            ],
            [
                'requests_count' => 0,
                'quota_limit' => $quotaLimit,
                'last_reset' => $today,
            ]
        );

        $usage->increment('requests_count');
        
        return $usage;
    }

    public static function getUsageForTenant(string $tenantId, Carbon $date = null): ?self
    {
        $date = $date ?? Carbon::today();
        
        return self::where('tenant_id', $tenantId)
                  ->where('usage_date', $date)
                  ->first();
    }

    public static function getTotalUsageForTenant(string $tenantId, Carbon $startDate = null, Carbon $endDate = null): int
    {
        $query = self::where('tenant_id', $tenantId);
        
        if ($startDate) {
            $query->where('usage_date', '>=', $startDate);
        }
        
        if ($endDate) {
            $query->where('usage_date', '<=', $endDate);
        }
        
        return $query->sum('requests_count');
    }

    // Rate Limiting Methods
    public function hasExceededQuota(): bool
    {
        return $this->requests_count >= $this->quota_limit;
    }

    public function getRemainingQuota(): int
    {
        return max(0, $this->quota_limit - $this->requests_count);
    }

    public function getUsagePercentage(): float
    {
        if ($this->quota_limit <= 0) {
            return 0;
        }
        
        return ($this->requests_count / $this->quota_limit) * 100;
    }

    // Reset Methods
    public function resetUsage(): void
    {
        $this->update([
            'requests_count' => 0,
            'last_reset' => now(),
        ]);
    }

    public static function resetDailyUsage(): void
    {
        $yesterday = Carbon::yesterday();
        
        self::where('usage_date', '<', $yesterday)
            ->where('requests_count', '>', 0)
            ->update([
                'requests_count' => 0,
                'last_reset' => now(),
            ]);
    }

    // Scopes
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForDate($query, Carbon $date)
    {
        return $query->where('usage_date', $date);
    }

    public function scopeExceededQuota($query)
    {
        return $query->whereRaw('requests_count >= quota_limit');
    }

    public function scopeWithinQuota($query)
    {
        return $query->whereRaw('requests_count < quota_limit');
    }

    // Statistics Methods
    public static function getTopUsageTenants(int $limit = 10, Carbon $date = null): \Illuminate\Database\Eloquent\Collection
    {
        $date = $date ?? Carbon::today();
        
        return self::where('usage_date', $date)
                  ->orderBy('requests_count', 'desc')
                  ->limit($limit)
                  ->get();
    }

    public static function getDailyStats(Carbon $date = null): array
    {
        $date = $date ?? Carbon::today();
        
        $stats = self::where('usage_date', $date);
        
        return [
            'total_requests' => $stats->sum('requests_count'),
            'total_tenants' => $stats->count(),
            'avg_requests_per_tenant' => $stats->avg('requests_count'),
            'max_requests' => $stats->max('requests_count'),
            'tenants_exceeded_quota' => $stats->whereRaw('requests_count >= quota_limit')->count(),
        ];
    }

    // Legacy compatibility methods for existing services
    public static function recordRequest(string $tenantId, ?string $keyId = null, ?string $ipAddress = null): void
    {
        self::incrementUsage($tenantId);
    }

    public static function getTodayRequests(string $keyId): int
    {
        // Since central model doesn't track by key_id, we'll return 0
        // This can be enhanced if needed
        return 0;
    }

    public static function getTenantStats(string $tenantId, ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $totalUsage = self::getTotalUsageForTenant($tenantId, $startDate, $endDate);
        $todayUsage = self::getUsageForTenant($tenantId);
        
        return [
            'total_requests' => $totalUsage,
            'today_requests' => $todayUsage ? $todayUsage->requests_count : 0,
            'quota_limit' => $todayUsage ? $todayUsage->quota_limit : 50000,
            'remaining_quota' => $todayUsage ? $todayUsage->getRemainingQuota() : 50000,
            'usage_percentage' => $todayUsage ? $todayUsage->getUsagePercentage() : 0,
            'quota_exceeded' => $todayUsage ? $todayUsage->hasExceededQuota() : false,
        ];
    }
} 