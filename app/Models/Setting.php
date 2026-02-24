<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $table = 'settings';
    
    public $timestamps = false;
    
    protected $fillable = [
        'document_name',
        'fields',
    ];

    protected $casts = [
        'fields' => 'array',
    ];

    /**
     * Get setting by document name
     */
    public static function getByDocument($documentName)
    {
        $rec = self::where('document_name', $documentName)->first();
        return $rec ? $rec->fields : [];
    }

    /**
     * Update or create setting by document name
     */
    public static function updateByDocument($documentName, array $fields)
    {
        return self::updateOrCreate(
            ['document_name' => $documentName],
            ['fields' => $fields]
        );
    }

    /**
     * Get a specific field from a document
     */
    public static function getField($documentName, $fieldName, $default = null)
    {
        $rec = self::where('document_name', $documentName)->first();
        if (!$rec || !$rec->fields) {
            return $default;
        }
        return $rec->fields[$fieldName] ?? $default;
    }

    /**
     * Set a specific field in a document
     */
    public static function setField($documentName, $fieldName, $value)
    {
        $rec = self::where('document_name', $documentName)->first();
        $fields = $rec && $rec->fields ? $rec->fields : [];
        $fields[$fieldName] = $value;
        
        return self::updateOrCreate(
            ['document_name' => $documentName],
            ['fields' => $fields]
        );
    }

    /**
     * Common document name scopes
     */
    public function scopeGlobalSettings($query)
    {
        return $query->where('document_name', 'globalSettings');
    }

    public function scopePlaceholderImage($query)
    {
        return $query->where('document_name', 'placeHolderImage');
    }

    public function scopeAdminCommission($query)
    {
        return $query->where('document_name', 'AdminCommission');
    }

    public function scopeRestaurant($query)
    {
        return $query->where('document_name', 'restaurant');
    }
}

