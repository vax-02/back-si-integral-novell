<?php

namespace App\Http\Controllers;

use App\Models\Pay;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;

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
            $pays = Pay::with('concept.career')
                ->where('student_id', $request->student_id)
                ->get();

            $groupedPays = $pays->groupBy('concept.career.id')
                ->map(function ($payments) {

                    return [
                        'career_id' => $payments->first()->concept->career->id,
                        'career_name' => $payments->first()->concept->career->name,
                        'payments' => $payments,
                        'total' => $payments->sum('amount')
                    ];

                })
                ->values();
            return response()->json([
                'pays' => $groupedPays,
                
            ]);
        }catch(Exception $e){

        }
    }
    public function dataCards(){
        try{
            $now = Carbon::now();
            return response()->json([
                'pays_for_month' => Pay::whereYear('created_at', $now->year)
                    ->whereMonth('created_at', $now->month)
                    ->sum('amount'),
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
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Pay $pay)
    {
        //
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
        //
    }
}
