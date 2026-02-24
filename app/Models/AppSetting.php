<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $table = 'app_settings';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'update_url',
        'ios_version',
        'android_build',
        'ios_build',
        'min_required_version',
        'app_name',
        'package_name',
        'app_type',
        'last_updated',
        'force_update',
        'update_message',
        'android_version',
        'latest_version',
        'android_update_url',
        'ios_update_url',
    ];

    protected $casts = [
        'force_update' => 'boolean',
    ];

    /**
     * Get the version info settings
     */
    public static function getVersionInfo()
    {
        return self::where('id', 'version_info')->first();
    }

    public static function getrestaurantVersionInfo()
    {
        return self::where('app_type', 'restaurant')->first();
    }

    /**
     * Update or create version info settings
     */
    public static function updateVersionInfo(array $data)
    {
        return self::updateOrCreate(
            ['id' => 'version_info'],
            $data
        );
    }

    /**
     * Get force update status
     */
    public function isForceUpdateEnabled()
    {
        return (bool) $this->force_update;
    }

    /**
     * Scope to get version info
     */
    public function scopeVersionInfo($query)
    {
        return $query->where('id', 'version_info');
    }
}

