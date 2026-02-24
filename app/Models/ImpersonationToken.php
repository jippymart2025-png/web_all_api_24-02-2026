<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImpersonationToken extends Model
{
    use HasFactory;

    protected $table = 'impersonation_tokens';

    protected $fillable = [
        'token',
        'user_id',
        'restaurant_id',
        'expires_at',
    ];

    public $timestamps = true;

    // Owner user relationship
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
