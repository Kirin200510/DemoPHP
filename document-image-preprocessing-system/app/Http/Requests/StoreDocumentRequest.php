<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:10240',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image.required' => 'Vui lòng chọn một hình ảnh giấy tờ.',
            'image.image' => 'Tệp đã chọn phải là một hình ảnh hợp lệ.',
            'image.mimes' => 'Hình ảnh chỉ hỗ trợ định dạng JPG, JPEG, PNG hoặc WEBP.',
            'image.max' => 'Dung lượng hình ảnh không được vượt quá 10 MB.',
        ];
    }
}
