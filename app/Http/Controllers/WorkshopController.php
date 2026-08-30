<?php

namespace App\Http\Controllers;

use App\Models\Workshop;
use App\Models\WorkshopModule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkshopController extends Controller
{
    public function index()
    {
        try {
            $workshops = Workshop::withCount('modules')->withCount('editions')->get();
            $active = Workshop::where('status', true)->count();

            return response()->json([
                'workshops' => $workshops,
                'total' => $workshops->count(),
                'active' => $active,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al obtener talleres', 'error' => $e->getMessage()], 500);
        }
    }

    public function simple()
    {
        try {
            $workshops = Workshop::select('id', 'name')->where('status', true)->get();
            return response()->json(['workshops' => $workshops]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error'], 500);
        }
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        try {
            $workshop = Workshop::create($data);
            return response()->json($workshop, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al crear taller', 'error' => $e->getMessage()], 500);
        }
    }

    public function show(Workshop $workshop)
    {
        try {
            $workshop->load(['modules' => fn ($q) => $q->orderBy('order'), 'editions']);
            return response()->json($workshop);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al obtener taller', 'error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, Workshop $workshop)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        try {
            $workshop->update($data);
            return response()->json($workshop);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al actualizar taller', 'error' => $e->getMessage()], 500);
        }
    }

    public function toggleStatus(Workshop $workshop)
    {
        try {
            $workshop->status = !$workshop->status;
            $workshop->save();
            return response()->json([
                'status' => $workshop->status,
                'message' => $workshop->status ? 'Taller activado.' : 'Taller desactivado.',
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'No se pudo cambiar el estado.', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy(Workshop $workshop)
    {
        $hasEditions = $workshop->editions()->exists();

        if ($hasEditions) {
            return response()->json(['message' => 'No se puede eliminar el taller porque tiene ediciones relacionadas.'], 409);
        }

        try {
            $workshop->delete();
            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json(['message' => 'No se pudo eliminar taller.', 'error' => $e->getMessage()], 500);
        }
    }

    public function storeModule(Request $request, Workshop $workshop)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        try {
            $lastOrder = $workshop->modules()->max('order') ?? 0;

            $module = $workshop->modules()->create([
                'name' => $data['name'],
                'order' => $lastOrder + 1,
            ]);

            return response()->json($module, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al crear módulo', 'error' => $e->getMessage()], 500);
        }
    }

    public function updateModule(Request $request, Workshop $workshop, WorkshopModule $module)
    {
        if ($module->workshop_id !== $workshop->id) {
            return response()->json(['message' => 'El módulo no pertenece a este taller.'], 404);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'order' => 'required|integer|min:1',
        ]);

        try {
            $module->update($data);
            return response()->json($module);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al actualizar módulo', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroyModule(Workshop $workshop, WorkshopModule $module)
    {
        if ($module->workshop_id !== $workshop->id) {
            return response()->json(['message' => 'El módulo no pertenece a este taller.'], 404);
        }

        try {
            $module->delete();
            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json(['message' => 'No se pudo eliminar módulo.', 'error' => $e->getMessage()], 500);
        }
    }
}
