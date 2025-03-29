<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateQrOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // return [
        //     //
        //     'stocks' => 'required|array|min:1',
        //     'stocks.*.stock_id' => 'required|exists:stocks,id',
        //     'stocks.*.quantity'=> 'required|integer|min:1',
        // ];

        return [
            'stocks' => 'required|array|min:1',
            'stocks.*.stock_id' => 'required|exists:stocks,id',
            'stocks.*.quantity' => 'nullable|integer|min:1', // **Əgər null gəlirsə, 1 olsun**
            'stocks.*.detail_id' => 'nullable|exists:stock_details,id', // **Detail ID varsa, yoxlanacaq**
        ];
    }
}

