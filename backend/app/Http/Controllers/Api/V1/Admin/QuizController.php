<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Quiz\QuizRequest;
use App\Http\Resources\QuizResource;
use App\Models\Course;
use App\Models\Quiz;
use App\Services\Quiz\QuizService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class QuizController extends Controller
{
    public function __construct(private readonly QuizService $quizzes) {}

    public function index(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('manageContent', $course);

        return response()->json([
            'data' => QuizResource::collection($this->quizzes->list($course))->resolve($request),
        ]);
    }

    public function show(Course $course, Quiz $quiz): QuizResource
    {
        Gate::authorize('manageContent', $course);

        return new QuizResource($quiz->load(['chapter:id,title', 'lesson:id,title', 'questions.options'])->loadCount('questions'));
    }

    public function store(QuizRequest $request, Course $course): JsonResponse
    {
        $quiz = $this->quizzes->create($course, $request->validated());

        return (new QuizResource($quiz->load(['chapter:id,title', 'lesson:id,title'])->loadCount('questions')))
            ->response($request)->setStatusCode(201);
    }

    public function update(QuizRequest $request, Course $course, Quiz $quiz): QuizResource
    {
        return new QuizResource(
            $this->quizzes->update($course, $quiz, $request->validated())
                ->load(['chapter:id,title', 'lesson:id,title'])->loadCount('questions'),
        );
    }

    public function destroy(Course $course, Quiz $quiz): Response
    {
        Gate::authorize('manageContent', $course);

        $this->quizzes->delete($course, $quiz);

        return response()->noContent();
    }
}
