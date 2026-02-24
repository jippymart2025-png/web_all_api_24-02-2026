<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DailyCheckin extends Model
{
    use HasFactory;

    protected $table = 'daily_checkins';

    protected $fillable = [
        'user_id',
        'checkin_date',
        'streak_day_number',
        'coins_awarded',
        'idempotency_key'
    ];

    protected $casts = [
        'checkin_date' => 'date',
        'streak_day_number' => 'integer',
        'coins_awarded' => 'integer',
        'created_at' => 'datetime',
    ];

    public $timestamps = false;

    const CREATED_AT = 'created_at';

    /* -----------------------------------
       Relationships
    ------------------------------------*/

    public function user()
    {
        return $this->belongsTo(AppUser::class, 'user_id');
    }
}
