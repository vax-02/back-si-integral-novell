<?php

namespace App\Http\Controllers;

use App\Models\Docente;
use App\Models\Material;
use App\Models\Parallel;
use App\Models\Student;
use App\Models\StudentParallel;
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
            'subject_id'    => 'nullable|integer|exists:subjects,id',
            'subject_ids'   => 'nullable|array',
            'subject_ids.*' => 'integer|exists:subjects,id',
            'subjects'      => 'nullable|array',
            'subjects.*.subject_id'     => 'required|integer|exists:subjects,id',
            'subjects.*.all_parallels'  => 'nullable|in:0,1,true,false,on,off',
            'subjects.*.parallel_ids'   => 'nullable|array',
            'subjects.*.parallel_ids.*' => 'integer|exists:parallels,id',
            'title'         => 'required|string|max:255',
            'description'   => 'nullable|string|max:1000',
            'file'          => 'required|file|mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,ppt,pptx|max:20480',
            'all_parallels' => 'nullable|in:0,1,true,false,on,off',
            'parallel_ids'  => 'nullable|array',
            'parallel_ids.*' => 'integer|exists:parallels,id',
        ]);

        try {
            $user = $request->user();
            $docente = Docente::where('user_id', $user->id)->first();

            if (!$docente) {
                return response()->json(['error' => 'Tu usuario no tiene perfil de docente activo'], 404);
            }

            // Normalizar selección: subjects[] (por materia con sus paralelos) o subject_ids/subject_id
            if ($request->filled('subjects')) {
                $selections = collect($request->subjects)->map(function ($item) {
                    return [
                        'subject_id'    => (int) $item['subject_id'],
                        'all_parallels' => filter_var($item['all_parallels'] ?? false, FILTER_VALIDATE_BOOLEAN),
                        'parallel_ids'  => array_map('intval', $item['parallel_ids'] ?? []),
                    ];
                })->values();
            } else {
                $subjectIds = collect($request->filled('subject_ids') ? $request->subject_ids : [])
                    ->map('intval')
                    ->push((int) $request->subject_id)
                    ->unique()
                    ->filter()
                    ->values()
                    ->all();

                if (empty($subjectIds)) {
                    return response()->json(['error' => 'Selecciona al menos una materia.'], 422);
                }

                $allParallels = $request->boolean('all_parallels', true);
                $globalParallelIds = $request->filled('parallel_ids') ? $request->parallel_ids : [];
                $selections = collect($subjectIds)->map(function ($sid) use ($allParallels, $globalParallelIds) {
                    return [
                        'subject_id'    => $sid,
                        'all_parallels' => $allParallels,
                        'parallel_ids'  => $globalParallelIds,
                    ];
                });
            }

            // Validar que cada materia tenga visibilidad (todos los paralelos o al menos uno específico)
            foreach ($selections as $sel) {
                if (!$sel['all_parallels'] && empty($sel['parallel_ids'])) {
                    return response()->json([
                        'error' => 'Selecciona al menos un paralelo para la materia ' . $sel['subject_id'] . '.',
                    ], 422);
                }
            }

            $file = $request->file('file');
            $fileName = time() . '_' . $file->getClientOriginalName();
            $filePath = $file->storeAs('materials/' . $docente->id, $fileName, 'public');

            $materials = [];

            // Crear un material por materia seleccionada (comparten el mismo archivo físico)
            foreach ($selections as $sel) {
                $material = Material::create([
                    'docente_id'    => $docente->id,
                    'subject_id'    => $sel['subject_id'],
                    'title'         => $request->title,
                    'description'   => $request->description,
                    'file_path'     => $filePath,
                    'file_name'     => $file->getClientOriginalName(),
                    'file_type'     => substr($file->getClientMimeType(), 0, 191),
                    'all_parallels' => $sel['all_parallels'],
                ]);

                // Si es para paralelos específicos, registrar en material_parallel
                if (!$sel['all_parallels'] && !empty($sel['parallel_ids'])) {
                    $material->parallels()->attach(array_unique($sel['parallel_ids']));
                }

                $material->load(['subject:id,name,sigla', 'parallels:id,paralelo,turno']);
                $materials[] = $material;
            }

            return response()->json([
                'message'   => 'Material subido correctamente.',
                'materials' => $materials,
            ], 201);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Actualizar visibilidad de un material (materias y paralelos visibles)
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'all_parallels' => 'nullable|in:0,1,true,false,on,off',
            'parallel_ids'  => 'nullable|array',
            'parallel_ids.*' => 'integer|exists:parallels,id',
            'subject_ids'   => 'nullable|array',
            'subject_ids.*' => 'integer|exists:subjects,id',
        ]);

        try {
            $user = $request->user();
            $docente = Docente::where('user_id', $user->id)->first();

            if (!$docente) {
                return response()->json(['error' => 'Docente no encontrado'], 404);
            }

            $material = Material::where('id', $id)
                ->where('docente_id', $docente->id)
                ->firstOrFail();

            $allParallels = $request->boolean('all_parallels', false);
            $parallelIds = $request->filled('parallel_ids') ? $request->parallel_ids : [];

            // Materias que verán el material (por defecto la materia actual)
            $subjectIds = $request->filled('subject_ids')
                ? array_map('intval', $request->subject_ids)
                : [(int) $material->subject_id];
            $subjectIds = array_values(array_unique(array_filter($subjectIds)));

            if (empty($subjectIds)) {
                return response()->json(['error' => 'Selecciona al menos una materia.'], 422);
            }

            // Grupo: materiales que comparten el mismo archivo físico (misma subida)
            $group = Material::where('file_path', $material->file_path)->get();

            // 1) Crear materiales para las materias recién seleccionadas
            $newMaterials = [];
            foreach ($subjectIds as $subjectId) {
                if ($group->contains('subject_id', $subjectId)) {
                    continue;
                }
                $newMaterial = Material::create([
                    'docente_id'    => $docente->id,
                    'subject_id'    => $subjectId,
                    'title'         => $material->title,
                    'description'   => $material->description,
                    'file_path'     => $material->file_path,
                    'file_name'     => $material->file_name,
                    'file_type'     => $material->file_type,
                    'all_parallels' => $allParallels,
                ]);
                if (!$allParallels && !empty($parallelIds)) {
                    $newMaterial->parallels()->attach(array_unique($parallelIds));
                }
                $newMaterial->load(['subject:id,name,sigla', 'parallels:id,paralelo,turno']);
                $newMaterials[] = $newMaterial;
            }

            // 2) Eliminar materiales del grupo que ya no están seleccionados
            foreach ($group as $m) {
                if (!in_array($m->subject_id, $subjectIds)) {
                    $m->parallels()->detach();
                    $m->delete();
                }
            }

            // 3) Actualizar visibilidad (paralelos) de los materiales que permanecen
            $updatedMaterials = [];
            foreach ($group as $m) {
                if (!in_array($m->subject_id, $subjectIds)) {
                    continue;
                }
                $m->all_parallels = $allParallels;
                $m->save();
                $m->parallels()->detach();
                if (!$allParallels && !empty($parallelIds)) {
                    $m->parallels()->attach(array_unique($parallelIds));
                }
                $m->load(['subject:id,name,sigla', 'parallels:id,paralelo,turno']);
                $updatedMaterials[] = $m;
            }

            // Eliminar archivo físico si el grupo quedó vacío
            if (Material::where('file_path', $material->file_path)->count() === 0) {
                Storage::disk('public')->delete($material->file_path);
            }

            return response()->json([
                'message'   => 'Visibilidad actualizada correctamente.',
                'materials' => array_merge($updatedMaterials, $newMaterials),
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Listar materiales enlazados a un paralelo específico (uso administrativo)
     */
    public function materialsByParallel(Parallel $parallel)
    {
        try {
            $parallel->load('course');

            $materials = Material::where(function ($q) use ($parallel) {
                // Enlazados explícitamente al paralelo
                $q->whereHas('parallels', function ($q2) use ($parallel) {
                    $q2->where('parallel_id', $parallel->id);
                });

                // Materiales visibles en todos los paralelos del curso de la materia
                if ($parallel->course) {
                    $q->orWhere(function ($q3) use ($parallel) {
                        $q3->where('all_parallels', true)
                           ->whereHas('subject', function ($q4) use ($parallel) {
                               $q4->where('career_id', $parallel->course->career_id)
                                  ->where('level', $parallel->course->level);
                           });
                    });
                }
            })
            ->with([
                'subject:id,name,sigla',
                'parallels:id,paralelo,turno',
                'docente.user:id,name,first_lastname,second_lastname',
            ])
            ->orderBy('created_at', 'desc')
            ->get();

            return response()->json([
                'materials' => $materials,
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Listar materiales visibles para el estudiante logueado
     */
    public function studentMaterials(Request $request)
    {
        try {
            $user = $request->user();
            $student = Student::where('user_id', $user->id)->first();

            if (!$student) {
                return response()->json(['materials' => []]);
            }

            // Obtener paralelos del estudiante
            $studentParallels = StudentParallel::where('student_id', $student->id)
                ->where('status', true)
                ->pluck('parallel_id')
                ->toArray();

            if (empty($studentParallels)) {
                return response()->json(['materials' => []]);
            }

            // Materiales visibles: all_parallels = true O que tengan relación con los paralelos del estudiante
            $materials = Material::where(function ($q) use ($studentParallels) {
                    $q->where('all_parallels', true)
                      ->orWhereHas('parallels', function ($q2) use ($studentParallels) {
                          $q2->whereIn('parallel_id', $studentParallels);
                      });
                })
                ->with([
                    'subject:id,name,sigla,level',
                    'subject.career:id,name',
                    'parallels:id,paralelo,turno',
                    'docente.user:id,name,first_lastname,second_lastname',
                ])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'materials' => $materials,
            ]);
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

            // Eliminar archivo físico solo si ninguna otra materia lo comparte
            $shared = Material::where('file_path', $material->file_path)
                ->where('id', '!=', $material->id)
                ->exists();

            if (!$shared) {
                Storage::disk('public')->delete($material->file_path);
            }

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
