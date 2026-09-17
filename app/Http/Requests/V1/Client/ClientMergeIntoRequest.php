<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Client;

use App\Http\Requests\V1\BaseFormRequest;
use App\Models\Client;
use App\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Korridor\LaravelModelValidationRules\Rules\ExistsEloquent;

/**
 * @property Organization $organization Organization from model binding
 * @property Client $client Client from model binding
 */
class ClientMergeIntoRequest extends BaseFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<string|ValidationRule>>
     */
    public function rules(): array
    {
        $sourceId = $this->client->getKey();
        $allowedNameIds = array_values(array_filter([
            $sourceId,
            is_string($this->input('client_id')) ? $this->input('client_id') : null,
        ]));

        return [
            // ID of the client that keeps the projects (destination)
            'client_id' => [
                'required',
                'string',
                'not_in:'.$sourceId,
                ExistsEloquent::make(Client::class, null, function (Builder $builder): Builder {
                    /** @var Builder<Client> $builder */
                    return $builder->whereBelongsTo($this->organization, 'organization');
                })->uuid(),
            ],
            // ID of the client whose name should be kept. Must be the source or destination client.
            'name_client_id' => [
                'required',
                'string',
                'uuid',
                'in:'.implode(',', $allowedNameIds),
            ],
        ];
    }

    public function getClientId(): string
    {
        return (string) $this->input('client_id');
    }

    public function getNameClientId(): string
    {
        return (string) $this->input('name_client_id');
    }
}
