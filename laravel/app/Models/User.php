<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $table = 'User';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    public const CREATED_AT = 'createdAt';

    public const UPDATED_AT = 'updatedAt';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'username',
        'name',
        'grade',
        'class',
        'avatar',
        'role',
        'status',
        'email',
        'emailVerified',
        'lastLogin',
        'lastLoginIp',
        'passwordChangedAt',
        'forcePasswordChange',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'createdAt' => 'datetime',
            'updatedAt' => 'datetime',
            'emailVerified' => 'boolean',
            'lastLogin' => 'datetime',
            'passwordChangedAt' => 'datetime',
            'forcePasswordChange' => 'boolean',
        ];
    }
}
