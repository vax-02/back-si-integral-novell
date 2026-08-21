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
        try {
            $i = Institution::first();

            return response()->json([
                'address' => $i->address ?? '',
                'cellphone' => $i->cellphone ?? '',
                'email' => $i->email ?? '',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'address' => '',
                'cellphone' => '',
                'email' => '',
            ]);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Institution $institution)
    {
        try {
            $validated = $request->validate([
                'address' => 'required|string|max:255',
                'cellphone' => 'required|string|digits_between:8,10',
                'email' => 'required|email|max:255',
            ]);

            $institution->update($validated);

            return response()->json([
                'message' => 'Información actualizada',
                'institution' => $institution,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Error al actualizar la institución',
            ], 500);
        }
    }
}
