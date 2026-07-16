<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use Exception;
use Illuminate\Http\Request;

class InstitutionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try{
            $i = Institution::first();

            return response()->json([
                'address' => $i->address,
                'cellphone' => $i->cellphone,
                'email' => $i->email,
            ]);
        }catch(Exception $e){
            return response()->json([]);
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
    public function show(Institution $institution)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Institution $institution)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Institution $institution)
    {
        
        try{
            $request->validate([
                'address' => 'string',
                'cellphone' => 'max:8',
                'email' => 'email'
            ]);
            $institution->address = $request->address;
            $institution->cellphone = $request->cellphone;
            $institution->email = $request->email;

            $institution->save();
            return response()->json([
                'message' => 'Informacion actualizada'
            ]);
        }catch(Exception $e){
            return response()->json([]);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Institution $institution)
    {
        //
    }
}
