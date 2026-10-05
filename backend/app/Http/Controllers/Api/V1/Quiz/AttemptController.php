<?php

namespace App\Http\Controllers\Api\V1\Quiz;

use App\Http\Controllers\Controller;
use App\Http\Resources\Quiz\AttemptInProgressResource;
use App\Http\Resources\Quiz\AttemptResultResource;
use App\Http\Resources\Quiz\AttemptSummaryResource;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\Quiz\QuizAttemptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Quyền (sở hữu khóa, lượt của chính mình) kiểm trong QuizAttemptService — đúng mã COURSE_NOT_OWNED / 404. */
class AttemptController extends Controller
{
    public function __construct(private readonly QuizAttemptService $attempts) {}

    /** 201 lượt mới · 200 lượt đang làm được tiếp tục. */
    public function store(Request $request, Quiz $quiz): JsonResponse
    {
        ['attempt' => $attempt, 'created' => $created] = $this->attempts->start($this->user($request), $quiz);

        return $this->present($attempt)->response()->setStatusCode($created ? 201 : 200);
    }

    public function show(Request $request, int $attempt): JsonResponse
    {
        return $this->present($this->attempts->show($this->user($request), $attempt))->response();
    }

    public function submit(Request $request, int $attempt): JsonResponse
    {
        return $this->present($this->attempts->submit($this->user($request), $attempt))->response();
    }

    public function index(Request $request, Quiz $quiz): JsonResponse
    {
        $history = $this->attempts->history($this->user($request), $quiz);

        return response()->json([
            'data' => AttemptSummaryResource::collection($history['attempts'])->resolve($request),
            'best_score' => $history['best_score'],
            'attempts_count' => $history['attempts_count'],
        ]);
    }

    private function present(QuizAttempt $attempt): AttemptInProgressResource|AttemptResultResource
    {
        $questions = $this->attempts->questionsFor($attempt);

        return $attempt->isSubmitted()
            ? new AttemptResultResource($attempt, $questions)
            : new AttemptInProgressResource($attempt, $questions);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
