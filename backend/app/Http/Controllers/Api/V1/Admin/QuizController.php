<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Quiz\QuizRequest;
use App\Http\Resources\QuizResource;
use App\Models\Course;
use App\Models\Quiz;
use App\Services\Quiz\QuizAttemptUsage;
use App\Services\Quiz\QuizService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class QuizController extends Controller
{
    public function __construct(private readonly QuizService $quizzes, private readonly QuizAttemptUsage $usage) {}

    public function index(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('manageContent', $course);

        $list = $this->quizzes->list($course);
        $this->usage->markQuizzes($list);

        return response()->json([
            'data' => QuizResource::collection($list)->resolve($request),
        ]);
    }

    public function show(Course $course, Quiz $quiz): QuizResource
    {
        Gate::authorize('manageContent', $course);

        $quiz->load(['chapter:id,title', 'lesson:id,title', 'questions.options'])->loadCount('questions');
        $this->usage->markQuizzes([$quiz]);
        $this->usage->markQuestions($quiz->questions);

        return new QuizResource($quiz);
    }

    public function store(QuizRequest $request, Course $course): JsonResponse
    {
        $quiz = $this->quizzes->create($course, $request->validated());

        $quiz->load(['chapter:id,title', 'lesson:id,title'])->loadCount('questions');
        $quiz->setAttribute('has_attempts', false);

        return (new QuizResource($quiz))
            ->response($request)->setStatusCode(201);
    }

    public function update(QuizRequest $request, Course $course, Quiz $quiz): QuizResource
    {
        $updated = $this->quizzes->update($course, $quiz, $request->validated())
            ->load(['chapter:id,title', 'lesson:id,title'])->loadCount('questions');
        $this->usage->markQuizzes([$updated]);

        return new QuizResource($updated);
    }

    public function destroy(Course $course, Quiz $quiz): Response
    {
        Gate::authorize('manageContent', $course);

        $this->quizzes->delete($course, $quiz);

        return response()->noContent();
    }
}
