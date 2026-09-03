<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Project;

use App\Http\Requests\V1\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

class ProjectDestroyRequest extends BaseFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'force' => [
                'string',
                'in:true,false',
            ],
        ];
    }

    public function getForce(): bool
    {
        return $this->input('force', 'false') === 'true';
    }
}
