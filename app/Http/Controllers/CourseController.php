<?php

namespace App\Http\Controllers;

use App\Models\Course;
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
            $perPage = $request->input('per_page', 10);
            $search  = $request->input('search');

            $query = Course::with('career');

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('gestion',  'like', "%$search%")
                      ->orWhere('paralelo', 'like', "%$search%")
                      ->orWhere('turno',    'like', "%$search%")
                      ->orWhereHas('career', function ($qc) use ($search) {
                          $qc->where('name', 'like', "%$search%");
                      });
                });
            }

            $total = Course::count();

            return response()->json([
                'courses' => $query->paginate($perPage),
                'total'   => $total,
            ]);
        } catch (Exception $exception) {
            return $this->errorResponse($exception);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'career_id' => ['required', 'integer', 'exists:careers,id'],
            'gestion'   => ['required', 'string', 'max:25'],
            'paralelo'  => ['required', 'string', 'max:2', 'regex:/^[A-Za-zÁÉÍÓÚáéíóúÑñ]+$/u'],
            'limit'     => ['required', 'integer', 'min:1'],
            'turno'     => ['required', 'in:Mañana,Tarde,Noche'],
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
            'gestion'   => ['sometimes', 'string', 'max:25'],
            'paralelo'  => ['sometimes', 'string', 'max:2', 'regex:/^[A-Za-zÁÉÍÓÚáéíóúÑñ]+$/u'],
            'limit'     => ['sometimes', 'integer', 'min:1'],
            'turno'     => ['sometimes', 'in:Mañana,Tarde,Noche'],
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
