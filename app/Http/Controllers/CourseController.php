<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Parallel;
use App\Models\Student;
use Exception;
use Illuminate\Http\Request;

class CourseController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            // Endpoint de selección: devuelve los cursos de una carrera como array plano
            if ($request->has('career_id')) {
                $courses = Course::where('career_id', $request->career_id)
                    ->withCount('parallels')
                    ->orderBy('level')
                    ->get();

                return response()->json([
                    'courses' => $courses,
                ]);
            }

            $perPage = $request->input('per_page', 10);
            $search  = $request->input('search');

            $query = Course::with('career')->withCount('parallels');
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%$search%")
                    ->orWhereHas('career', function ($qc) use ($search) {
                        $qc->where('name', 'like', "%$search%");
                    });
                });
            }

            $courses = $query->paginate($perPage);
            
            $total = Course::count();
            $totalLimit = Parallel::sum('limit');
            $totalStudentsForCareer = Student::whereHas('user', function($q) {
                $q->where('status', 1);
            })->count();

            return response()->json([
                'courses' => $courses,
                'total' => $total,
                'total_limit' => $totalLimit,
                'total_students' => $totalStudentsForCareer,
            ]);
        } catch (Exception $exception) {
            return response()->json([],500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'career_id' => ['required', 'integer', 'exists:careers,id'],
            'name'      => ['required', 'string', 'max:255'],
            'level'     => ['required', 'integer', 'min:1'],
        ]);

        try {
            $course = Course::create($validated);

            return response()->json([
                'message' => 'Curso creado exitosamente.',
                'data'    => $course->load('career'),
            ], 201);
        } catch (Exception $exception) {
            return $this->errorResponse($exception);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Course $course)
    {
        try {
            return response()->json($course->load('career'));
        } catch (Exception $exception) {
            return $this->errorResponse($exception);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Course $course)
    {
        $validated = $request->validate([
            'career_id' => ['sometimes', 'integer', 'exists:careers,id'],
            'name'      => ['sometimes', 'string', 'max:255'],
            'level'     => ['sometimes', 'integer', 'min:1'],
        ]);

        try {
            $course->update($validated);

            return response()->json([
                'message' => 'Curso actualizado exitosamente.',
                'data'    => $course->load('career'),
            ]);
        } catch (Exception $exception) {
            return $this->errorResponse($exception);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Course $course)
    {
        try {
            $course->delete();

            return response()->json([
                'message' => 'Curso eliminado exitosamente.',
            ]);
        } catch (Exception $exception) {
            return $this->errorResponse($exception);
        }
    }
}
