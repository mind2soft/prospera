<?php

namespace App\Http\Requests\Sharing;

use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * A Share-drawer request on the route-bound resource (budget, ledger,
 * envelope or profile), authorized through the resource's policy before
 * the payload is validated.
 */
abstract class SharedResourceRequest extends FormRequest
{
    private const RESOURCE_PARAMETERS = ['budget', 'ledger', 'envelope', 'profile'];

    /**
     * The policy ability the request requires on the resource.
     */
    abstract protected function ability(): string;

    public function authorize(): Response
    {
        return Gate::inspect($this->ability(), $this->resource());
    }

    protected function resource(): Model
    {
        foreach (self::RESOURCE_PARAMETERS as $parameter) {
            $resource = $this->route($parameter);

            if ($resource instanceof Model) {
                return $resource;
            }
        }

        throw new LogicException('The route has no shareable resource.');
    }
}
