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
        return $this->user()?->can('documents.upload') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'images' => [
                'required_without:image',
                'array',
                'min:1',
                'max:6',
            ],
            'images.*' => [
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:10240',
            ],
            'image' => [
                'nullable',
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
            'images.required_without' => 'Vui lòng chọn ít nhất một hình ảnh giấy tờ.',
            'images.array' => 'Danh sách hình ảnh không hợp lệ.',
            'images.min' => 'Vui lòng chọn ít nhất một hình ảnh giấy tờ.',
            'images.max' => 'Mỗi lần chỉ được chọn tối đa 6 hình ảnh.',
            'images.*.image' => 'Mỗi tệp đã chọn phải là một hình ảnh hợp lệ.',
            'images.*.mimes' => 'Hình ảnh chỉ hỗ trợ định dạng JPG, JPEG, PNG hoặc WEBP.',
            'images.*.max' => 'Mỗi hình ảnh không được vượt quá 10 MB.',
            'image.required' => 'Vui lòng chọn một hình ảnh giấy tờ.',
            'image.image' => 'Tệp đã chọn phải là một hình ảnh hợp lệ.',
            'image.mimes' => 'Hình ảnh chỉ hỗ trợ định dạng JPG, JPEG, PNG hoặc WEBP.',
            'image.max' => 'Dung lượng hình ảnh không được vượt quá 10 MB.',
        ];
    }
}
