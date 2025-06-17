<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Initiative extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'hourly_rate',
        'is_active',
    ];

    protected $casts = [
        'hourly_rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Get the project filters for this initiative
     */
    public function projectFilters(): HasMany
    {
        return $this->hasMany(InitiativeProjectFilter::class);
    }

    /**
     * Get the access records for this initiative
     */
    public function accessRecords(): HasMany
    {
        return $this->hasMany(InitiativeAccess::class);
    }

    /**
     * Get users with access to this initiative
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'initiative_access')
            ->withPivot('access_type')
            ->withTimestamps();
    }

    /**
     * Get projects associated with this initiative
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(JiraProject::class, 'initiative_project_filters')
            ->withPivot(['required_labels', 'epic_key'])
            ->withTimestamps();
    }

    /**
     * Scope to only active initiatives
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Check if user has access to this initiative
     */
    public function hasUserAccess(User $user, string $accessType = 'read'): bool
    {
        return $this->accessRecords()
            ->where('user_id', $user->id)
            ->where('access_type', $accessType)
            ->exists();
    }

    /**
     * Calculate total hours for this initiative within date range
     */
    public function calculateHours(?string $startDate = null, ?string $endDate = null): float
    {
        // This will be implemented when we create the service
        return 0.0;
    }

    /**
     * Calculate total cost for this initiative
     */
    public function calculateCost(?string $startDate = null, ?string $endDate = null): float
    {
        if (!$this->hourly_rate) {
            return 0.0;
        }
        
        return $this->calculateHours($startDate, $endDate) * $this->hourly_rate;
    }
}
