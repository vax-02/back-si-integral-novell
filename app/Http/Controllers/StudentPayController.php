<?php

namespace App\Http\Controllers;

use App\Models\Pay;
use App\Models\Student;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StudentPayController extends Controller
{
    public function myPays()
    {
        try {
            $user = Auth::user();

            $student = Student::where('user_id', $user->id)->first();

            if (!$student) {
                return response()->json(['message' => 'Estudiante no encontrado'], 404);
            }

            $pays = Pay::with(['concept.career', 'casher' => function ($query) {
                $query->select('id', 'name', 'first_lastname', 'second_lastname');
            }])
                ->where('student_id', $student->id)
                ->get();

            $groupedPays = $pays->groupBy('concept.career.id')
                ->map(function ($payments) {
                    return [
                        'career_id' => $payments->first()->concept->career->id,
                        'career_name' => $payments->first()->concept->career->name,
                        'payments' => $payments,
                        'total' => $payments->sum('amount'),
                    ];
                })
                ->values();

            return response()->json([
                'pays' => $groupedPays,
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
