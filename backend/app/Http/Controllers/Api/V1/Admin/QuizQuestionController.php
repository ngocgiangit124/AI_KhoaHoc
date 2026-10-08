<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Quiz\QuizQuestionOrderRequest;
use App\Http\Requests\Admin\Quiz\QuizQuestionRequest;
use App\Http\Resources\QuizQuestionResource;
use App\Models\Course;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Services\Quiz\QuizContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class QuizQuestionController extends Controller
{
    public function __construct(private readonly QuizContentService $content) {}

    public function index(Request $request, Course $course, Quiz $quiz): JsonResponse
    {
        Gate::authorize('manageContent', $course);

        return response()->json([
            'data' => QuizQuestionResource::collection($this->content->list($quiz))->resolve($request),
        ]);
    }

    /** SLN7: đổi thứ tự câu; chỉ đổi position, id giữ nguyên. */
    public function reorder(QuizQuestionOrderRequest $request, Course $course, Quiz $quiz): JsonResponse
    {
        return response()->json([
            'data' => QuizQuestionResource::collection($this->content->reorder($course, $quiz, $request->questionIds()))->resolve($request),
        ]);
    }

    public function show(Course $course, Quiz $quiz, QuizQuestion $question): QuizQuestionResource
    {
        Gate::authorize('manageContent', $course);

        return new QuizQuestionResource($question->load('options'));
    }

    public function store(QuizQuestionRequest $request, Course $course, Quiz $quiz): JsonResponse
    {
        return (new QuizQuestionResource($this->content->create($course, $quiz, $request->payload())))
            ->response($request)->setStatusCode(201);
    }

    /** Có thể trả câu hỏi với `id` MỚI (copy-on-write khi câu đã có lượt làm): client dùng id trong response. */
    public function update(QuizQuestionRequest $request, Course $course, Quiz $quiz, QuizQuestion $question): JsonResponse
    {
        // Luôn 200 (kể cả copy-on-write tạo bản ghi mới) để client xử lý một kiểu.
        return (new QuizQuestionResource($this->content->update($course, $quiz, $question, $request->payload())))
            ->response($request)->setStatusCode(200);
    }

    public function destroy(Course $course, Quiz $quiz, QuizQuestion $question): Response
    {
        Gate::authorize('manageContent', $course);

        $this->content->delete($course, $quiz, $question);

        return response()->noContent();
    }
}
