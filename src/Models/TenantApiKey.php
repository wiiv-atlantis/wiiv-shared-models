<?php

namespace Wiiv\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;

class TenantApiKey extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'tenant_api_keys';

    protected $fillable = [
        'tenant_id',
        'key_id',
        'secret_hash',
        'permissions',
        'rate_limits',
        'allowed_ips',
        'environment',
        'active',
        'last_used_at',
    ];

    protected $casts = [
        'permissions' => 'array',
        'rate_limits' => 'array',
        'allowed_ips' => 'array',
        'active' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    protected $hidden = [
        'secret_hash',
    ];

    protected $appends = ['requests_count'];

    // Accessor for requests_count
    public function getRequestsCountAttribute()
    {
        // If the relation is already loaded, use it. Otherwise, query it.
        if ($this->relationLoaded('usageRecords')) {
            return $this->usageRecords->count();
        }
        return $this->usageRecords()->count();
    }

    // Relations
    public function tenant(): BelongsTo
    {
        // This will be resolved by each project's own Tenant model
        $tenantModel = config('wiiv-shared.tenant_model', 'App\\Models\\Tenant');
        return $this->belongsTo($tenantModel, 'tenant_id', 'id');
    }

    // public function usageRecords(): \Illuminate\Database\Eloquent\Relations\HasMany
    // {
    //     $usageModel = config('wiiv-shared.tenant_usage_model', TenantUsage::class);
    //     return $this->hasMany($usageModel, 'api_key_id', 'id');
    // }

    // API Key Generation
    public static function generateKey(string $tenantId, string $environment = 'test', array $permissions = []): self
    {
        $prefix = 'pk'; // Public key prefix
        $random = Str::random(32);
        $keyId = "{$prefix}_{$environment}_wiiv_shop_{$random}";
        
        $secret = Str::random(64);
        $secretHash = Hash::make($secret);
        
        $defaultRateLimits = config('wiiv-shared.default_rate_limits.public_key', [
            'per_minute' => 1000,
            'daily' => 50000
        ]);
        
        $apiKey = self::create([
            'tenant_id' => $tenantId,
            'key_id' => $keyId,
            'secret_hash' => $secretHash,
            'permissions' => $permissions,
            'rate_limits' => $defaultRateLimits,
            'environment' => $environment,
            'active' => true,
        ]);
        
        // Store unhashed secret temporarily for return
        $apiKey->secret = $secret;
        
        return $apiKey;
    }

    public static function generateSecretKey(string $tenantId, string $environment = 'test', array $permissions = []): self
    {
        $prefix = 'sk'; // Secret key prefix
        $random = Str::random(32);
        $keyId = "{$prefix}_{$environment}_wiiv_shop_{$random}";
        
        $secret = Str::random(64);
        $secretHash = Hash::make($secret);
        
        $defaultRateLimits = config('wiiv-shared.default_rate_limits.secret_key', [
            'per_minute' => 5000,
            'daily' => 100000
        ]);
        
        $apiKey = self::create([
            'tenant_id' => $tenantId,
            'key_id' => $keyId,
            'secret_hash' => $secretHash,
            'permissions' => $permissions,
            'rate_limits' => $defaultRateLimits,
            'environment' => $environment,
            'active' => true,
        ]);
        
        // Store unhashed secret temporarily for return
        $apiKey->secret = $secret;
        
        return $apiKey;
    }

    // Permission Methods (Spatie Integration)
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions ?? []);
    }

    public function hasAnyPermission(array $permissions): bool
    {
        return !empty(array_intersect($permissions, $this->permissions ?? []));
    }

    public function hasAllPermissions(array $permissions): bool
    {
        return empty(array_diff($permissions, $this->permissions ?? []));
    }

    // Spatie Permission Mapping
    public function getSpatiePermissions(): array
    {
        $mapping = config('wiiv-shared.permission_mapping', [
            'orders:read' => 'view orders',
            'orders:write' => ['create orders', 'update orders'],
            'orders:delete' => 'delete orders',
            'products:read' => 'view products',
            'products:write' => ['create products', 'update products'],
            'products:delete' => 'delete products',
            'admin:access' => 'access admin panel',
        ]);

        $spatiePermissions = [];
        
        foreach ($this->permissions ?? [] as $apiScope) {
            if (isset($mapping[$apiScope])) {
                $permissions = is_array($mapping[$apiScope]) ? $mapping[$apiScope] : [$mapping[$apiScope]];
                $spatiePermissions = array_merge($spatiePermissions, $permissions);
            }
        }

        return array_unique($spatiePermissions);
    }

    // Utility Methods
    public function updateLastUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }

    public function isExpired(): bool
    {
        // You can implement expiration logic here if needed
        return false;
    }

    public function isPublicKey(): bool
    {
        return Str::startsWith($this->key_id, 'pk_');
    }

    public function isSecretKey(): bool
    {
        return Str::startsWith($this->key_id, 'sk_');
    }

    public function isTestEnvironment(): bool
    {
        return $this->environment === 'test';
    }

    public function isLiveEnvironment(): bool
    {
        return $this->environment === 'live';
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeEnvironment($query, string $environment)
    {
        return $query->where('environment', $environment);
    }

    // Additional utility methods for API usage
    public function verifySecret(string $secret): bool
    {
        return Hash::check($secret, $this->secret_hash);
    }

    public function isIpWhitelisted(?string $ip): bool
    {
        if (!$this->allowed_ips || !$ip) {
            return true; // No restriction or no IP provided
        }

        return in_array($ip, $this->allowed_ips);
    }

    public static function findActiveByKeyId(string $keyId): ?self
    {
        return static::where('key_id', $keyId)
                     ->where('active', true)
                     ->first();
    }

    public static function validateKey(string $keyId, string $secret): ?self
    {
        $apiKey = static::findActiveByKeyId($keyId);
        
        if (!$apiKey || !$apiKey->verifySecret($secret)) {
            return null;
        }

        return $apiKey;
    }

    public static function getActiveKeysForTenant(string $tenantId): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('tenant_id', $tenantId)
                     ->where('active', true)
                     ->orderBy('created_at', 'desc')
                     ->get();
    }

    public static function getTenantKeyStats(string $tenantId): array
    {
        $keys = static::where('tenant_id', $tenantId)->get();
        
        return [
            'total_keys' => $keys->count(),
            'active_keys' => $keys->where('active', true)->count(),
            'public_keys' => $keys->where('key_id', 'like', 'pk_%')->count(),
            'secret_keys' => $keys->where('key_id', 'like', 'sk_%')->count(),
            'live_keys' => $keys->where('environment', 'live')->count(),
            'test_keys' => $keys->where('environment', 'test')->count(),
        ];
    }
} 