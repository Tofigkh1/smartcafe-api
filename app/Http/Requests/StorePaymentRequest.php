<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'shares' => 'required|array|min:1',
            'shares.*.type' => 'required|in:cash,bank,customer_balance',
            'shares.*.amount' => 'required|numeric|min:0.01',
            'shares.*.customer_id' => 'nullable|exists:customers,id', // Only validate if type is customer_balance
        ];
    }
}
