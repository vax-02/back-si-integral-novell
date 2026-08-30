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

            $pays = Pay::with(['concept.career', 'workshopConcept.edition.workshop', 'casher' => function ($query) {
                $query->select('id', 'name', 'first_lastname', 'second_lastname');
            }])
                ->where('student_id', $student->id)
                ->get();

            $groupedPays = $pays->groupBy(function ($pay) {
                if ($pay->source === 'workshop' && $pay->workshopConcept) {
                    return 'workshop_' . $pay->workshopConcept->edition->workshop->id;
                }
                return 'career_' . ($pay->concept->career->id ?? 0);
            })->map(function ($payments) {
                $first = $payments->first();
                if ($first->source === 'workshop' && $first->workshopConcept) {
                    return [
                        'source' => 'workshop',
                        'career_id' => null,
                        'career_name' => $first->workshopConcept->edition->workshop->name ?? 'Taller',
                        'payments' => $payments,
                        'total' => $payments->sum('amount'),
                    ];
                }
                return [
                    'source' => 'career',
                    'career_id' => $first->concept->career->id ?? null,
                    'career_name' => $first->concept->career->name ?? 'Sin carrera',
                    'payments' => $payments,
                    'total' => $payments->sum('amount'),
                ];
            })->values();

            return response()->json([
                'pays' => $groupedPays,
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
