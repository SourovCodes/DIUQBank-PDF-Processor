<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ExtractPdfPagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $pages = $this->input('pages');

        if (is_string($pages)) {
            $expanded = $this->expandPageString($pages);

            if ($expanded === null) {
                return;
            }

            $this->merge(['pages' => $expanded]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'pdf' => ['required', 'file', 'mimetypes:application/pdf,application/x-pdf', 'max:30720'],
            'pages' => ['required', 'array', 'min:1'],
            'pages.*' => ['integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pages.required' => 'The pages field is required.',
            'pages.array' => 'The pages field must contain valid page numbers or ranges (e.g. "1,3,5-7").',
            'pages.*.integer' => 'Each page must be a positive integer.',
            'pages.*.min' => 'Each page must be a positive integer.',
        ];
    }

    /**
     * Expand a comma-separated page string with optional ranges into an integer list.
     * Returns null when the string is malformed so validation surfaces the error.
     *
     * @return array<int, int>|null
     */
    private function expandPageString(string $pages): ?array
    {
        $trimmed = trim($pages);

        if ($trimmed === '') {
            return null;
        }

        $result = [];

        foreach (explode(',', $trimmed) as $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                return null;
            }

            if (str_contains($segment, '-')) {
                $parts = explode('-', $segment);

                if (count($parts) !== 2) {
                    return null;
                }

                $start = trim($parts[0]);
                $end = trim($parts[1]);

                if (! ctype_digit($start) || ! ctype_digit($end)) {
                    return null;
                }

                $startInt = (int) $start;
                $endInt = (int) $end;

                if ($startInt < 1 || $endInt < $startInt) {
                    return null;
                }

                for ($i = $startInt; $i <= $endInt; $i++) {
                    $result[] = $i;
                }
            } else {
                if (! ctype_digit($segment)) {
                    return null;
                }

                $result[] = (int) $segment;
            }
        }

        return $result;
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
