<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Feedback\StoreFeedbackRequest;
use App\Http\Resources\FeedbackResource;
use App\Models\Feedback;
use Illuminate\Http\Request;

class FeedbackController extends BaseApiController
{
    public function store(StoreFeedbackRequest $request)
    {
        $authUser = $request->user();
        $data = $request->validated();

        $feedback = new Feedback();
        $feedback->user_id = $authUser ? $authUser->id : null;
        $feedback->type = $data['type'] ?? null;
        $feedback->message = $data['message'];
        $feedback->metadata = $data['metadata'] ?? null;
        $feedback->save();

        $feedback->load('user');

        return $this->success(new FeedbackResource($feedback), 'Feedback submitted successfully', 201);
    }
}
