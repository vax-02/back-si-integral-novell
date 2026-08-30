<?php

namespace App\Http\Controllers;

use App\Helpers\NumberToString;
use Illuminate\Support\Facades\DB;
use App\Models\Institution;
use App\Models\Pay;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\ResponsePrepared;

class PayController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $request->validate([
            "student_id" => ['required','integer']
        ]);
        try{
            $pays = Pay::with(['concept.career', 'workshopConcept.edition.workshop', 'casher' => function($query) {
                $query->select('id', 'name','first_lastname','second_lastname');
            }])->where('student_id', $request->student_id)->get();

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
                        'total' => $payments->sum('amount')
                    ];
                }
                return [
                    'source' => 'career',
                    'career_id' => $first->concept->career->id ?? null,
                    'career_name' => $first->concept->career->name ?? 'Sin carrera',
                    'payments' => $payments,
                    'total' => $payments->sum('amount')
                ];
            })->values();

            return response()->json([
                'pays' => $groupedPays,
            ]);
        }catch(Exception $e){
            return response()->json(['pays' => [], 'error' => $e->getMessage()], 500);
        }
    }
    public function dataCards(){
        try{
            $now = Carbon::now();
            return response()->json([
                'pays_for_month' => Pay::where('status',1)->whereYear('created_at', $now->year)
                    ->whereMonth('created_at', $now->month)
                    ->sum(DB::raw('amount - discount')),
                'total_pays' => Pay::where('status',1)->count()
            ]);
        }catch(Exception $e){

        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $source = $request->input('source', 'career');

        if ($source === 'workshop') {
            $request->validate([
                'student_id' => 'required|integer|exists:students,id',
                'workshop_concept_id' => 'required|integer|exists:workshop_concepts,id',
                'amount' => 'required|numeric|min:0',
                'discount' => 'nullable|numeric|min:0',
                'description' => 'nullable|string',
                'payment_method' => 'required|in:efectivo,qr',
            ]);
        } else {
            $request->validate([
                'student_id' => 'required|integer|exists:students,id',
                'concept_id' => 'required|integer|exists:concepts,id',
                'amount' => 'required|numeric|min:0',
                'discount' => 'nullable|numeric|min:0',
                'description' => 'nullable|string',
                'payment_method' => 'required|in:efectivo,qr',
            ]);
        }

        try {
            $payData = [
                'user_id' => auth()->id(),
                'student_id' => $request->student_id,
                'amount' => $request->amount,
                'discount' => $request->discount ?? 0,
                'description' => $request->description,
                'payment_method' => $request->payment_method,
                'source' => $source,
                'status' => 1,
            ];

            if ($source === 'workshop') {
                $payData['workshop_concept_id'] = $request->workshop_concept_id;
            } else {
                $payData['concept_id'] = $request->concept_id;
            }

            $pay = Pay::create($payData);

            if ($source === 'workshop') {
                $pay->load(['workshopConcept.edition.workshop', 'student.user', 'casher']);
            } else {
                $pay->load(['concept.career', 'student.user', 'casher']);
            }

            return response()->json([
                'message' => 'Pago registrado exitosamente',
                'pay' => $pay,
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Error al registrar el pago',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Pay $pay)
    {
        //
    }

    public function receipt(Request $request, Pay $pay)
    {
        try{
            $pay->load(['concept.career', 'workshopConcept.edition.workshop', 'student.user', 'casher']);
            $institution = Institution::first();
            $numeroLetras = NumberToString::convertir($pay->amount - $pay->discount);
            $pdf = Pdf::loadView('receipt', compact('pay', 'numeroLetras'));
            $pdf->setPaper('letter', 'portrait');

            return $pdf->stream('recibo_pago_' . $pay->id . '.pdf');
        }catch(Exception $e){
            return $e;
        }

    }


    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Pay $pay)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Pay $pay)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Pay $pay)
    {
        try{
            $pay->status = 0;
            $pay->save();
            return response()->json(['message' => 'Pago anulado'],200);
        }catch(Exception $e){
            return response()->json([''],500);
        }
    }


}
