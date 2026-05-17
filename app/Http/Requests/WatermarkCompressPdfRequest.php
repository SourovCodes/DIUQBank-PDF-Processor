<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class WatermarkCompressPdfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'pdf' => ['required', 'file', 'mimetypes:application/pdf,application/x-pdf'],
            'watermark_text' => ['required', 'string', 'max:255'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $validationException = new ValidationException($validator);

        throw new HttpResponseException(response()->json([
            'message' => $validationException->getMessage(),
            'errors' => $validationException->errors(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
