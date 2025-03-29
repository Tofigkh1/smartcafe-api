<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Stock extends Model
{
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'stock_group_id',
        'name',
        'image',
        'show_on_qr',
        'price',
        'amount',
        'critical_amount',
        'alert_critical',
        'order_start',
        'order_stop',
        'description'
    ];

    // Relationship with StockGroup
    public function stockGroup()
    {
        return $this->belongsTo(StockGroup::class)->withDefault(); // withDefault ensures it returns null when not set
    }

    // Relationship with Restaurant
    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }
    public function orders()
    {
        return $this->belongsToMany(Order::class, 'order_stock')
            ->withPivot(['id', 'quantity', 'detail_id']) 
            ->withTimestamps();
    }
    
    

    public function details()
    {
        return $this->hasMany(StockDetail::class);
    }


}


