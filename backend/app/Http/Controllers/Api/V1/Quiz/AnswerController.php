<?php

namespace App\Http\Controllers\Api\V1\Quiz;

use App\Http\Controllers\Controller;
use App\Http\Requests\Quiz\SaveAnswerRequest;
use App\Models\User;
use App\Services\Quiz\QuizAttemptService;
use Illuminate\Http\Response;

class AnswerController extends Controller
{
    public function __construct(private readonly QuizAttemptService $attempts) {}

    public function update(SaveAnswerRequest $request, int $attempt, int $question): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->attempts->saveAnswer($user, $attempt, $question, $request->integer('option_id'));

        return response()->noContent();
    }
}
