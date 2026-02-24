<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MartSubcategory extends Model
{
    use HasFactory;

    protected $table = 'mart_subcategories';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'title',
        'description',
        'parent_category_id',
        'parent_category_title',
        'section',
        'section_order',
        'category_order',
        'subcategory_order',
        'mart_id',
        'review_attributes',
        'publish',
        'show_in_homepage',
        'migratedBy',
        'photo'
    ];

    protected $casts = [
        'publish' => 'boolean',
        'show_in_homepage' => 'boolean',
        'section_order' => 'integer',
        'category_order' => 'integer',
        'subcategory_order' => 'integer'
    ];

    /**
     * Get the parent category
     */
    public function parentCategory()
    {
        return $this->belongsTo(MartCategory::class, 'parent_category_id', 'id');
    }
}

