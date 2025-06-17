<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the initiative access records for this user
     */
    public function initiativeAccess(): HasMany
    {
        return $this->hasMany(InitiativeAccess::class);
    }

    /**
     * Get initiatives this user has access to
     */
    public function initiatives(): BelongsToMany
    {
        return $this->belongsToMany(Initiative::class, 'initiative_access')
            ->withPivot('access_type')
            ->withTimestamps();
    }

    /**
     * Get initiatives where this user has admin access
     */
    public function adminInitiatives(): BelongsToMany
    {
        return $this->initiatives()->wherePivot('access_type', 'admin');
    }

    /**
     * Get initiatives where this user has read access
     */
    public function readInitiatives(): BelongsToMany
    {
        return $this->initiatives()->wherePivot('access_type', 'read');
    }

    /**
     * Check if user has access to a specific initiative
     */
    public function hasInitiativeAccess(Initiative $initiative, string $accessType = 'read'): bool
    {
        return $this->initiatives()
            ->where('initiatives.id', $initiative->id)
            ->wherePivot('access_type', $accessType)
            ->exists();
    }
}
