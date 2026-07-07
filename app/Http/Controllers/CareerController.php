<?php

namespace App\Http\Controllers;

use App\Models\Career;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class CareerController extends Controller
{
    public function downloadTemplate()
    {
        return response()->download(public_path('templates/plantillaDeMaterias.xlsx'));
    }

    public function index()
    {
        try {
            $careers = Career::withCount('subjects')->withCount('students')->get();
            $careersActivas = Career::where('status', 1)->count();
            $totalSubjects = $careers->sum('subjects_count');

            return response()->json([
                'careers' => $careers,
                'total' => $careers->count(),
                'totalSubjects' => $totalSubjects,
                'careersActivas' => $careersActivas,
            ]);
        } catch (\Exception $e) {
            return response()->json('error', 500);
        }
    }

    public function simple(){
        try {
            $careers = Career::select('id', 'name')->get();
        
            return response()->json([
                'careers' => $careers,
            ]);
        } catch (\Exception $e) {
            return response()->json('error', 500);
        }
    }
    public function create()
    {
        //
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'duration' => 'required|in:1 año,2 años,3 años',
        ]);

        try {
            $career = Career::create($request->all());
            return response()->json($career, 201);
        } catch (\Exception $e) {
            return response()->json('error', 500);
        }
    }

    public function importPreview(Request $request)
    {
        $data = $this->validateImportRequest($request);
        $rows = $this->loadImportRows($request->file('file'));
        $preview = $this->buildPreviewRows($rows);

        return response()->json([
            'career' => [
                'name' => $data['name'],
                'duration' => $data['duration'],
                'type' => $data['type'],
            ],
            'preview' => $preview,
            'valid_subjects' => collect($preview['rows'])->where('valid', true)->count(),
            'invalid_subjects' => collect($preview['rows'])->where('valid', false)->count(),
            'errors' => $preview['errors'],
        ]);
    }

    public function importConfirm(Request $request)
    {
        $data = $this->validateImportRequest($request);
        $rows = $this->loadImportRows($request->file('file'));
        $preview = $this->buildPreviewRows($rows);

        if (!empty($preview['errors']) || collect($preview['rows'])->contains(fn ($row) => ! $row['valid'])) {
            return response()->json([
                'message' => 'El archivo contiene errores de coherencia.',
                'preview' => $preview,
            ], 422);
        }

        DB::beginTransaction();

        try {
            $career = Career::create([
                'name' => $data['name'],
                'duration' => $data['duration'],
                'type' => $data['type'],
                'status' => 1,
            ]);

            $createdSubjectsBySigla = [];

            foreach ($preview['rows'] as $row) {
                if (! $row['valid']) {
                    continue;
                }

                $subject = Subject::create([
                    'name' => $row['name'],
                    'sigla' => $row['sigla'],
                    'level' => $row['level'],
                    'career_id' => $career->id,
                    'subject_id' => $this->resolvePrerequisiteId($row['prerequisite'] ?? null, $createdSubjectsBySigla),
                ]);

                $createdSubjectsBySigla[$row['sigla']] = $subject->id;
            }

            DB::commit();

            return response()->json([
                'message' => 'Plan de estudio importado correctamente.',
                'career' => $career,
                'subjects' => $preview['rows'],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'No se pudo guardar la importación.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(Career $career)
    {
        try {
            $career->load('subjects');

            $grouped = $career->subjects
                ->sortBy('level')
                ->groupBy('level')
                ->map(function ($items, $level) {
                    return [
                        'level' => (int) $level,
                        'subjects' => $items->values(),
                    ];
                })
                ->values();
            return response()->json([
                'id' => $career->id,
                'name' => $career->name,
                'duration' => $career->duration,
                'status' => $career->status,
                'type' => $career->type,
                'subjects_by_level' => $grouped,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener la carrera',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function edit(Career $career)
    {
        //
    }

    public function update(Request $request, Career $career)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'duration' => 'required|in:1 año,2 años,3 años',
        ]);

        try {
            $career->update($request->all());
            return response()->json($career);
        } catch (\Exception $e) {
            return response()->json('error', 500);
        }
    }

    public function destroy(Career $career)
    {
        $hasStudents = $career->students()->exists();
        $hasSubjects = $career->subjects()->exists();
        $hasCourses = $career->courses()->exists();

        if ($hasStudents || $hasSubjects || $hasCourses) {
            return response()->json([
                'message' => 'No se puede eliminar la carrera porque tiene estudiantes, materias o cursos relacionados.',
            ], 409);
        }

        try {
            $career->delete();
            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'No se pudo eliminar la carrera.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function storeSubject(Request $request, Career $career)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'sigla' => 'required|string|max:50|unique:subjects,sigla',
            'level' => 'required|integer|min:1',
            'subject_id' => 'nullable|exists:subjects,id',
        ]);

        $subject = $career->subjects()->create([
            'name' => $data['name'],
            'sigla' => $data['sigla'],
            'level' => $data['level'],
            'subject_id' => $data['subject_id'] ?? null,
        ]);

        return response()->json($subject, 201);
    }

    public function updateSubject(Request $request, Career $career, Subject $subject)
    {
        if ($subject->career_id !== $career->id) {
            return response()->json(['message' => 'La materia no pertenece a esta carrera.'], 404);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'sigla' => 'required|string|max:50|unique:subjects,sigla,' . $subject->id,
            'level' => 'required|integer|min:1',
            'subject_id' => 'nullable|exists:subjects,id',
        ]);

        $subject->update([
            'name' => $data['name'],
            'sigla' => $data['sigla'],
            'level' => $data['level'],
            'subject_id' => $data['subject_id'] ?? null,
        ]);

        return response()->json($subject);
    }

    public function deleteSubject(Career $career, Subject $subject)
    {
        if ($subject->career_id !== $career->id) {
            return response()->json(['message' => 'La materia no pertenece a esta carrera.'], 404);
        }

        $subject->delete();

        return response()->json(null, 204);
    }

    private function validateImportRequest(Request $request): array
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'duration' => 'required|in:1 año,2 años,3 años',
            'type' => 'required|in:Semestral,Anual',
            'file' => 'required|file|mimes:xlsx,xls',
        ]);

        return [
            'name' => $request->input('name'),
            'duration' => $request->input('duration'),
            'type' => $request->input('type'),
        ];
    }

   
    private function loadImportRows($file): array
{
    $spreadsheet = IOFactory::load($file->getRealPath());
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray();

    if (empty($rows)) {
        return [];
    }

    // Primera fila como encabezados
    $headerRow = array_values($rows[0] ?? []);
    $headers = array_map(
        fn ($header) => $this->normalizeHeader($header),
        $headerRow
    );

    $columnMap = [
        'sigla' => null,
        'name' => null,
        'level' => null,
        'prerequisite' => null,
    ];

    // Identificar posiciones de las columnas
    foreach ($headers as $index => $header) {

        if (in_array($header, ['sigla'])) {
            $columnMap['sigla'] = $index;

        } elseif (in_array($header, ['materia', 'nombre', 'name'])) {
            $columnMap['name'] = $index;

        } elseif (in_array($header, ['nivel', 'level'])) {
            $columnMap['level'] = $index;

        } elseif (in_array($header, [
            'pre_requisito',
            'pre requisito',
            'prerequisito',
            'prerequisite'
        ])) {
            $columnMap['prerequisite'] = $index;
        }
    }

    // Validar columnas obligatorias
    if (
        $columnMap['sigla'] === null ||
        $columnMap['name'] === null ||
        $columnMap['level'] === null
    ) {
        throw new \Exception(
            'El archivo Excel debe contener las columnas: SIGLA, MATERIA y NIVEL'
        );
    }

    $parsedRows = [];

    // Saltar la fila de encabezados y leer desde la fila 2
    foreach (array_slice($rows, 1) as $index => $row) {

        $rowValues = array_values($row);

        $parsedRows[] = [
            'row' => $index + 2,

            'sigla' => trim((string) $this->getCellValue(
                $rowValues,
                $columnMap['sigla']
            )),

            'name' => trim((string) $this->getCellValue(
                $rowValues,
                $columnMap['name']
            )),

            'level' => trim((string) $this->getCellValue(
                $rowValues,
                $columnMap['level']
            )),

            'prerequisite' => trim((string) $this->getCellValue(
                $rowValues,
                $columnMap['prerequisite']
            )),
        ];
    }

    return $parsedRows;
}

    private function getCellValue(array $rowValues, ?int $index): mixed
    {
        if ($index === null || ! array_key_exists($index, $rowValues)) {
            return '';
        }

        return $rowValues[$index];
    }

    private function buildPreviewRows(array $rows): array
    {
        $previewRows = [];
        $errors = [];
        $seenSiglas = [];
        $importedSubjectsBySigla = [];

        foreach ($rows as $rowData) {
            $rowErrors = [];
            $sigla = trim($rowData['sigla'] ?? '');
            $name = trim($rowData['name'] ?? '');
            $level = trim($rowData['level'] ?? '');
            $prerequisite = trim($rowData['prerequisite'] ?? '');

            if ($sigla === '') {
                $rowErrors[] = 'La sigla es obligatoria.';
            }

            if ($name === '') {
                $rowErrors[] = 'El nombre es obligatorio.';
            }

            if ($level === '' || ! is_numeric($level) || (int) $level < 1) {
                $rowErrors[] = 'El nivel debe ser un número entero mayor o igual a 1.';
            }

            if ($sigla !== '' && isset($seenSiglas[$sigla])) {
                $rowErrors[] = 'La sigla ya fue utilizada en otra fila del archivo.';
            }

            if ($sigla !== '' && Subject::where('sigla', $sigla)->exists()) {
                $rowErrors[] = 'La sigla ya existe en la base de datos.';
            }

            $resolvedPrerequisite = null;
            if ($prerequisite !== '') {
                $existingSubject = Subject::where('sigla', $prerequisite)->first();

                if ($existingSubject) {
                    $resolvedPrerequisite = $existingSubject->id;
                } else {
                    $importedSubject = $importedSubjectsBySigla[$prerequisite] ?? null;

                    if ($importedSubject && (int) $importedSubject['level'] < (int) $level) {
                        $resolvedPrerequisite = $prerequisite;
                    } else {
                        $rowErrors[] = 'El prerequisito no existe ni en la base de datos ni en una materia anterior del archivo.';
                    }
                }
            }

            if ($sigla !== '') {
                $seenSiglas[$sigla] = true;
            }

            if ($sigla !== '' && empty($rowErrors)) {
                $importedSubjectsBySigla[$sigla] = [
                    'level' => (int) $level,
                    'sigla' => $sigla,
                ];
            }

            $previewRows[] = [
                'row' => $rowData['row'],
                'sigla' => $sigla,
                'name' => $name,
                'level' => $level === '' ? null : (int) $level,
                'prerequisite' => $prerequisite,
                'prerequisite_id' => $resolvedPrerequisite,
                'valid' => empty($rowErrors),
                'errors' => $rowErrors,
            ];
        }

        foreach ($previewRows as $previewRow) {
            if (! $previewRow['valid']) {
                $errors[] = 'Fila ' . $previewRow['row'] . ': ' . implode(' ', $previewRow['errors']);
            }
        }

        return [
            'rows' => $previewRows,
            'errors' => $errors,
        ];
    }

    private function resolvePrerequisiteId(?string $prerequisite, array $createdSubjectsBySigla): ?int
    {
        if ($prerequisite === null || $prerequisite === '') {
            return null;
        }

        $existingSubject = Subject::where('sigla', $prerequisite)->first();
        if ($existingSubject) {
            return $existingSubject->id;
        }

        return $createdSubjectsBySigla[$prerequisite] ?? null;
    }

    private function normalizeHeader($header): string
    {
        $normalized = strtolower(trim((string) $header));
        $normalized = str_replace(['á', 'é', 'í', 'ó', 'ú'], ['a', 'e', 'i', 'o', 'u'], $normalized);
        $normalized = str_replace(' ', '_', $normalized);

        return $normalized;
    }
}
