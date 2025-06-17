<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InitiativeAccess extends Model
{
    use HasFactory;

    protected $table = 'initiative_access';

    protected $fillable = [
        'initiative_id',
        'user_id',
        'access_type',
    ];

    /**
     * Get the initiative this access record belongs to
     */
    public function initiative(): BelongsTo
    {
        return $this->belongsTo(Initiative::class);
    }

    /**
     * Get the user this access record belongs to
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to only admin access
     */
    public function scopeAdmin($query)
    {
        return $query->where('access_type', 'admin');
    }

    /**
     * Scope to only read access
     */
    public function scopeRead($query)
    {
        return $query->where('access_type', 'read');
    }

    /**
     * Check if this access record is admin level
     */
    public function isAdmin(): bool
    {
        return $this->access_type === 'admin';
    }

    /**
     * Check if this access record is read level
     */
    public function isRead(): bool
    {
        return $this->access_type === 'read';
    }
}
