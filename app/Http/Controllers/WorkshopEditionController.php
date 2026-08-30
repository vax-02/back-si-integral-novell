<?php

namespace App\Http\Controllers;

use App\Models\Pay;
use App\Models\Workshop;
use App\Models\WorkshopConcept;
use App\Models\WorkshopEdition;
use Illuminate\Http\Request;

class WorkshopEditionController extends Controller
{
    public function index(Request $request, Workshop $workshop)
    {
        try {
            $editions = $workshop->editions()->withCount('enrollments')->orderBy('start_date', 'desc')->get();

            return response()->json(['editions' => $editions]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al obtener ediciones', 'error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request, Workshop $workshop)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'shift' => 'required|in:Mañana,Tarde,Noche',
            'capacity' => 'required|integer|min:1',
        ]);

        try {
            $edition = $workshop->editions()->create($data);
            return response()->json($edition, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al crear edición', 'error' => $e->getMessage()], 500);
        }
    }

    public function show(WorkshopEdition $edition)
    {
        try {
            $edition->load(['workshop', 'enrollments.student', 'concepts']);
            return response()->json($edition);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al obtener edición', 'error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, WorkshopEdition $edition)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'shift' => 'required|in:Mañana,Tarde,Noche',
            'capacity' => 'required|integer|min:1',
        ]);

        try {
            $edition->update($data);
            return response()->json($edition);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al actualizar edición', 'error' => $e->getMessage()], 500);
        }
    }

    public function toggleStatus(WorkshopEdition $edition)
    {
        try {
            $edition->status = !$edition->status;
            $edition->save();
            return response()->json([
                'status' => $edition->status,
                'message' => $edition->status ? 'Edición activada.' : 'Edición desactivada.',
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'No se pudo cambiar el estado.', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy(WorkshopEdition $edition)
    {
        $hasEnrollments = $edition->enrollments()->exists();

        if ($hasEnrollments) {
            return response()->json(['message' => 'No se puede eliminar la edición porque tiene inscripciones.'], 409);
        }

        try {
            $edition->delete();
            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json(['message' => 'No se pudo eliminar edición.', 'error' => $e->getMessage()], 500);
        }
    }

    public function concepts(WorkshopEdition $edition)
    {
        try {
            $concepts = $edition->concepts()->orderBy('id')->get();
            return response()->json(['concepts' => $concepts]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al obtener conceptos', 'error' => $e->getMessage()], 500);
        }
    }

    public function storeConcept(Request $request, WorkshopEdition $edition)
    {
        $data = $request->validate([
            'type' => 'required|in:Inscripcion,Cuota,Otro',
            'description' => 'nullable|string|max:255',
            'amount' => 'required|numeric|min:0',
        ]);

        try {
            $concept = $edition->concepts()->create($data);
            return response()->json($concept, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al crear concepto', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroyConcept(WorkshopConcept $concept)
    {
        try {
            $hasPays = Pay::where('workshop_concept_id', $concept->id)->exists();
            if ($hasPays) {
                return response()->json(['message' => 'No se puede eliminar: el concepto tiene pagos registrados.'], 409);
            }
            $concept->delete();
            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json(['message' => 'No se pudo eliminar concepto.', 'error' => $e->getMessage()], 500);
        }
    }



    public function allConcepts()
    {
        try {
            $concepts = \App\Models\WorkshopConcept::with('edition.workshop:id,name')
                ->orderBy('id', 'desc')
                ->get();
            return response()->json(['concepts' => $concepts]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al obtener conceptos', 'error' => $e->getMessage()], 500);
        }
    }

    public function editionsSimple()
    {
        try {
            $editions = WorkshopEdition::with('workshop:id,name')
                ->where('status', true)
                ->get(['id', 'name', 'workshop_id', 'start_date', 'end_date', 'shift', 'capacity', 'status']);
            return response()->json(['editions' => $editions]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error'], 500);
        }
    }
}
