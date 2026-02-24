<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MartCategory extends Model
{
    use HasFactory;

    protected $table = 'mart_categories';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'title',
        'description',
        'section',
        'category_order',
        'section_order',
        'mart_id',
        'has_subcategories',
        'subcategories_count',
        'review_attributes',
        'migratedBy',
        'photo',
        'show_in_homepage',
        'publish'
    ];

    protected $casts = [
        'has_subcategories' => 'boolean',
        'show_in_homepage' => 'boolean',
        'publish' => 'boolean',
        'category_order' => 'integer',
        'section_order' => 'integer',
        'subcategories_count' => 'integer'
    ];
}

