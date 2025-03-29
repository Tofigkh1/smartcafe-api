<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockRequest extends FormRequest
{
    public function authorize()
    {
        // Add additional authorization logic if necessary
        return true;
    }

    public function rules()
    {
        return [
            'stock_group_id' => 'nullable|exists:stock_groups,id', // Group can be null
            'name' => 'required|string|max:255',
            'image' => 'nullable|image|max:2048', // Optional image with a size limit
            'show_on_qr' => 'required|boolean',
            'price' => 'required|numeric|min:0',
            'amount' => 'required|integer',
            'critical_amount' => 'required|integer|min:0',
            'alert_critical' => 'required|boolean',
            'order_start' => 'nullable|date_format:H:i',
            'order_stop' => 'nullable|date_format:H:i',
            'description'=>'nullable'
        ];
    }
}

