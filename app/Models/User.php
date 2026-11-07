<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Model;

class User extends Model
{
    protected static function booted()
    {
        static::updated(function (self $user) {
            if ($user->wasChanged(['password', 'is_admin', 'is_staff', 'banned'])) {
                (new \App\Services\AuthService($user))->removeAllSession();
            }
        });
    }

    protected $table = 'v2_user';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['password', 'password_algo', 'password_salt', 'remember_token'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];
}
