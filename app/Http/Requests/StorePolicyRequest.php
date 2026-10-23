<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * StorePolicyRequest — Form Request Validator
 *
 * Laravel's FormRequest automatically validates the HTTP request
 * before it reaches the controller. If validation fails,
 * it returns a 422 JSON response automatically.
 *
 * WHY NOT VALIDATE IN CONTROLLER?
 * Because this way, the controller code stays clean and readable.
 * The validation rules and error messages are all in one place.
 */
class StorePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // No auth required for this assignment
    }

    public function rules(): array
    {
        return [
            'client_request_id' => 'required|string|max:255',
            'agent_id'          => 'required|string|max:255',
            'customer_name'     => 'required|string|max:255',
            'date_of_birth'     => 'required|date|before:today',
            'destination'       => 'required|in:DOMESTIC,INTERNATIONAL',
            'trip_start'        => 'required|date',
            'trip_end'          => 'required|date|after_or_equal:trip_start',
            'plan_code'         => 'required|in:BASIC,PLUS,PREMIUM',
            'sum_insured'       => 'required|integer|in:100000,300000,500000',
        ];
    }

    public function messages(): array
    {
        return [
            'destination.in'    => 'destination must be DOMESTIC or INTERNATIONAL.',
            'plan_code.in'      => 'plan_code must be BASIC, PLUS, or PREMIUM.',
            'sum_insured.in'    => 'sum_insured must be 100000, 300000, or 500000.',
            'trip_end.after_or_equal' => 'trip_end must be on or after trip_start.',
            'date_of_birth.before'    => 'date_of_birth must be a date in the past.',
        ];
    }

    /**
     * Override to return JSON 422 instead of redirecting.
     * (Important for pure API applications.)
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422)
        );
    }
}
