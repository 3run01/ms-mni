<?php

namespace App\Rules;

use App\Services\Callback\CallbackUrlValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class CallbackUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! app(CallbackUrlValidator::class)->ehValida((string) $value)) {
            $fail('O :attribute deve ser uma URL https válida e não pode apontar para IP interno.');
        }
    }
}
