<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Car extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'service_id',
        'car_number',
        'brand',
        'model',
        'manufacture_year',
        'car_condition',
        'fuel_type',
        'transmission',
        'mileage',
        'seats',
        'color',
        'price',
        'description',
        'status',
        'is_available',
        'city',
        'address',
        'contact_phone',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function images()
    {
        return $this->hasMany(CarImage::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function sentSwaps()
    {
        return $this->hasMany(CarSwap::class, 'requester_car_id');
    }

    public function receivedSwaps()
    {
        return $this->hasMany(CarSwap::class, 'receiver_car_id');
    }
}