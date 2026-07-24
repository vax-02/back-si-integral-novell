<?php

namespace App\Http\Controllers;

use App\Models\Docente;
use App\Models\Material;
use App\Models\Parallel;
use Illuminate\Http\Request;
use Exception;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Type\Integer;

class MaterialController extends Controller
{
    /**
     * Listar materiales del docente logueado para una materia
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            $docente = Docente::where('user_id', $user->id)->first();

            if (!$docente) {
                return response()->json(['materials' => []]);
            }

            $query = Material::where('docente_id', $docente->id)
                ->with(['subject:id,name,sigla', 'parallels:id,paralelo,turno']);

            if ($request->filled('subject_id')) {
                $query->where('subject_id', $request->subject_id);
            }

            $materials = $query->orderBy('created_at', 'desc')->get();

            return response()->json([
                'materials' => $materials,
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Subir un nuevo material
     */
    public function store(Request $request)
    {
        $request->validate([
            'subject_id'    => 'required|integer|exists:subjects,id',
            'title'         => 'required|string|max:255',
            'description'   => 'nullable|string|max:1000',
            'file'          => 'required|file|mimes:doc,docx,xls,xlsx,ppt,pptx|max:20480',
            'all_parallels' => 'boolean',
            'parallel_ids'  => 'required_without:all_parallels|array',
            'parallel_ids.*' => 'integer|exists:parallels,id',
        ]);

        try {
            $user = $request->user();
            $docente = Docente::where('user_id', $user->id)->first();

            if (!$docente) {
                return response()->json(['error' => 'Docente no encontrado'], 404);
            }

            $file = $request->file('file');
            $fileName = time() . '_' . $file->getClientOriginalName();
            $filePath = $file->storeAs('materials/' . $docente->id, $fileName, 'public');

            $material = Material::create([
                'docente_id'    => $docente->id,
                'subject_id'    => $request->subject_id,
                'title'         => $request->title,
                'description'   => $request->description,
                'file_path'     => $filePath,
                'file_name'     => $file->getClientOriginalName(),
                'file_type'     => $file->getClientMimeType(),
                'all_parallels' => $request->boolean('all_parallels', false),
            ]);

            // Asignar paralelos si no es "todos"
            if (!$request->boolean('all_parallels') && $request->filled('parallel_ids')) {
                $material->parallels()->attach($request->parallel_ids);
            }

            $material->load(['subject:id,name,sigla', 'parallels:id,paralelo,turno']);

            return response()->json([
                'message'  => 'Material subido correctamente.',
                'material' => $material,
            ], 201);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Eliminar un material
     */
    public function destroy($id)
    {
        try {
            $user = request()->user();
            $docente = Docente::where('user_id', $user->id)->first();

            $material = Material::where('id', $id)
                ->where('docente_id', $docente->id)
                ->firstOrFail();

            // Eliminar archivo físico
            Storage::disk('public')->delete($material->file_path);

            // Eliminar relaciones
            $material->parallels()->detach();
            $material->delete();

            return response()->json([
                'message' => 'Material eliminado correctamente.',
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Descargar un material
     */
    public function download(Material $material)
    {
        try {

            $material = Material::findOrFail($material->id);

            if (!Storage::disk('public')->exists($material->file_path)) {
                return response()->json(['error' => 'Archivo no encontrado'], 404);
            }

     //       return Storage::disk('public')->download($material->file_path, $material->file_name);
            return response()->download(storage_path('app/public/' . $material->file_path),$material->file_name);

        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}