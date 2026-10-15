<?php

namespace App\Http\Requests;

class UpdateCommentRequest extends StoreCommentRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }
}
