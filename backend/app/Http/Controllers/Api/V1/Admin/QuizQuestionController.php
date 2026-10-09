<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Quiz\QuizQuestionOrderRequest;
use App\Http\Requests\Admin\Quiz\QuizQuestionRequest;
use App\Http\Resources\QuizQuestionResource;
use App\Models\Course;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Services\Quiz\QuizAttemptUsage;
use App\Services\Quiz\QuizContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class QuizQuestionController extends Controller
{
    public function __construct(private readonly QuizContentService $content, private readonly QuizAttemptUsage $usage) {}

    public function index(Request $request, Course $course, Quiz $quiz): JsonResponse
    {
        Gate::authorize('manageContent', $course);

        $list = $this->content->list($quiz);
        $this->usage->markQuestions($list);

        return response()->json([
            'data' => QuizQuestionResource::collection($list)->resolve($request),
        ]);
    }

    /** SLN7: đổi thứ tự câu; chỉ đổi position, id giữ nguyên. */
    public function reorder(QuizQuestionOrderRequest $request, Course $course, Quiz $quiz): JsonResponse
    {
        $list = $this->content->reorder($course, $quiz, $request->questionIds());
        $this->usage->markQuestions($list);

        return response()->json([
            'data' => QuizQuestionResource::collection($list)->resolve($request),
        ]);
    }

    public function show(Course $course, Quiz $quiz, QuizQuestion $question): QuizQuestionResource
    {
        Gate::authorize('manageContent', $course);

        $question->load('options');
        $this->usage->markQuestions([$question]);

        return new QuizQuestionResource($question);
    }

    public function store(QuizQuestionRequest $request, Course $course, Quiz $quiz): JsonResponse
    {
        $created = $this->content->create($course, $quiz, $request->payload());
        $created->setAttribute('has_attempts', false);

        return (new QuizQuestionResource($created))
            ->response($request)->setStatusCode(201);
    }

    /** Có thể trả câu hỏi với `id` MỚI (copy-on-write khi câu đã có lượt làm): client dùng id trong response. */
    public function update(QuizQuestionRequest $request, Course $course, Quiz $quiz, QuizQuestion $question): JsonResponse
    {
        // Luôn 200 (kể cả copy-on-write tạo bản ghi mới) để client xử lý một kiểu.
        $updated = $this->content->update($course, $quiz, $question, $request->payload());
        $this->usage->markQuestions([$updated]);

        return (new QuizQuestionResource($updated))
            ->response($request)->setStatusCode(200);
    }

    public function destroy(Course $course, Quiz $quiz, QuizQuestion $question): Response
    {
        Gate::authorize('manageContent', $course);

        $this->content->delete($course, $quiz, $question);

        return response()->noContent();
    }
}
