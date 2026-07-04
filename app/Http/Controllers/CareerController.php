<?php

namespace App\Http\Controllers;

use Illuminate\Support\Arr;

use App\Models\Career;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CareerController extends Controller{


    public function downloadTemplate()
    {
     //   return Storage::download('public/templates/plantillaDeMaterias.xlsx');
      //dd(Storage::exists('public/templates/plantillaDeMaterias.xlsx'));
        return response()->download(
        public_path('templates/plantillaDeMaterias.xlsx')
    );
    }
    
    public function index()
    {
        try{
            $careers = Career::withCount('subjects')->withCount('students')->get();
            $careersActivas = Career::where('status', 1)->count();
            $totalSubjects = $careers->sum('subjects_count');

            return response()->json(["careers" => $careers, "total" => $careers->count(), "totalSubjects" => $totalSubjects, "careersActivas" => $careersActivas]);
        } catch (\Exception $e) {
            return response()->json('error', 500);
        }
    }

    /**
     * 
     *  the form for creating a new resource.
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
        $request->validate([
            'name' => 'required|string|max:255',
            'duration' => 'required|in:1 año,2 años,3 años',
        ]);
        try{

            $career = Career::create($request->all());
            return response()->json($career, 201);
        } catch (\Exception $e) {
            return response()->json('error', 500);
        }
    }

    /**
     * Display the specified resource.
     */

    public function show(Career $career)
    {
        try {

            $career->load('subjects');

            $grouped = $career->subjects
                ->groupBy('level')
                ->map(function ($items, $level) {
                    return [
                        'level' => $level,
                        'subjects' => $items->values()
                    ];
                })
                ->values();

            return response()->json([
                'id' => $career->id,
                'name' => $career->name,
                'duration' => $career->duration,
                'status' => $career->status,
                'type' => $career->type,
                'subjects_by_level' => $grouped
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener la carrera',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Career $career)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Career $career)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'duration' => 'required|in:1 año,2 años,3 años',
        ]);
        try{
            $career->update($request->all());
            return response()->json($career);
        }catch (\Exception $e) {
            return response()->json('error', 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Career $career)
    {
        try{
            $career->delete();
            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json('error', 500);
        }
    }
}
