<?php

namespace App\Services;

use App\Models\EvaluationColumn;
use App\Models\Parallel;
use App\Models\Qualification;
use App\Models\Student;
use App\Models\Subject;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Support\Facades\DB;

class GradeExportService
{
    private const NOTA_MINIMA = 61;
    private const WEIGHT_THEORY = 0.3;
    private const WEIGHT_PRACTICE = 0.7;

    // Centralizador: subject columns G(7)-M(13), Estado=T(20), Observaciones=U(21)
    private const CENTRAL_FIRST_SUBJECT_COL = 7;   // G
    private const CENTRAL_LAST_SUBJECT_COL = 13;   // M
    private const CENTRAL_ESTADO_COL = 20;          // T
    private const CENTRAL_OBS_COL = 21;             // U
    private const CENTRAL_FIRST_DATA_ROW = 14;
    private const CENTRAL_LAST_DATA_ROW = 43;
    private const CENTRAL_LAST_COL = 'U';

    // Detalle materia: F(6)=PromTeorica, G(7)=PromPractica, H(8)=CalFinal, I(9)=Recuperacion, J(10)=Estado
    private const DETAIL_PROM_TEORICA_COL = 6;      // F
    private const DETAIL_PROM_PRACTICA_COL = 7;     // G
    private const DETAIL_FINAL_COL = 8;             // H
    private const DETAIL_RECUPERACION_COL = 9;      // I
    private const DETAIL_ESTADO_COL = 10;           // J
    private const DETAIL_FIRST_DATA_ROW = 13;
    private const DETAIL_LAST_DATA_ROW = 47;
    private const DETAIL_LAST_COL = 'J';

    private Parallel $parallel;
    private int $year;
    private array $subjects;
    private $columnsBySubject;
    private array $students;
    private $qualifications;
    private $docentes;

    public function generate(int $parallelId, int $year): string
    {
        $this->parallel = Parallel::with('course.career')->findOrFail($parallelId);
        $this->year = $year;
        $this->loadData();

        $spreadsheet = IOFactory::load(storage_path('app/templates/centralizador.xlsx'));

        // Get the centralizador sheet
        $central = $spreadsheet->getSheetByName('Centralizador Calificaciones')
            ?? $spreadsheet->getSheetByName('Centralizador')
            ?? $spreadsheet->getActiveSheet();

        // Get first materia sheet as base template
        $baseSheet = null;
        foreach ($this->subjects as $subject) {
            $found = $spreadsheet->getSheetByName($subject->sigla);
            if ($found) {
                $baseSheet = $found;
                break;
            }
        }
        // Fallback: use any sheet that's not the centralizador or LISTA
        if (!$baseSheet) {
            foreach ($spreadsheet->getSheetNames() as $name) {
                if (!str_starts_with($name, 'Centralizador') && !str_starts_with($name, 'LISTA')) {
                    $baseSheet = $spreadsheet->getSheetByName($name);
                    break;
                }
            }
        }

        // Remove all sheets except centralizador
        $keepNames = [$central->getTitle()];
        if ($baseSheet) {
            $keepNames[] = $baseSheet->getTitle();
        }

        foreach ($spreadsheet->getSheetNames() as $name) {
            if (in_array($name, $keepNames, true)) {
                continue;
            }
            $sheet = $spreadsheet->getSheetByName($name);
            if ($sheet) {
                $spreadsheet->removeSheetByIndex($spreadsheet->getIndex($sheet));
            }
        }

        $this->fillCentralizador($central);

        // Create a sheet for each subject
        foreach ($this->subjects as $subject) {
            if ($baseSheet) {
                $sheet = $baseSheet->copy();
            } else {
                $sheet = new Worksheet();
            }
            $sheet->setTitle($this->uniqueSheetTitle($spreadsheet, $subject->sigla));
            $spreadsheet->addSheet($sheet);
            $this->fillDetalleMateria($sheet, $subject);
        }

        // Remove the base template sheet
        if ($baseSheet && $spreadsheet->sheetNameExists($baseSheet->getTitle())) {
            $spreadsheet->removeSheetByIndex($spreadsheet->getIndex($baseSheet));
        }

        $this->cleanupBrokenReferences($spreadsheet);

        $writer = new Xlsx($spreadsheet);
        $path = tempnam(sys_get_temp_dir(), 'calif_') . '.xlsx';
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function loadData(): void
    {
        $course = $this->parallel->course;
        $careerId = $course->career_id;
        $level = $course->level;
        $parallelId = $this->parallel->id;

        $this->subjects = Subject::where('career_id', $careerId)
            ->where('level', $level)
            ->orderBy('name')
            ->get()
            ->all();

        $subjectIds = array_map(fn ($s) => $s->id, $this->subjects);

        $this->columnsBySubject = EvaluationColumn::where('parallel_id', $parallelId)
            ->whereIn('subject_id', $subjectIds)
            ->orderBy('subject_id')
            ->orderBy('order')
            ->get()
            ->groupBy('subject_id');

        $qualifications = Qualification::where('course_id', $course->id)
            ->where('parallel_id', $parallelId)
            ->whereIn('subject_id', $subjectIds)
            ->whereYear('updated_at', $this->year)
            ->with(['details', 'student.user'])
            ->get();

        $this->qualifications = $qualifications->keyBy(fn ($q) => $q->student_id . '_' . $q->subject_id);

        $studentIds = $qualifications->pluck('student_id')->unique()->values();

        $this->students = Student::whereIn('id', $studentIds)
            ->with('user')
            ->get()
            ->sortBy(fn ($s) => $this->studentFullName($s))
            ->values()
            ->all();

        $this->docentes = DB::table('docente_subject')
            ->where('parallel_id', $parallelId)
            ->whereIn('subject_id', $subjectIds)
            ->where('status', 1)
            ->pluck('docente_id', 'subject_id')
            ->all();
    }

    private function studentFullName($student): string
    {
        $u = $student->user;
        return trim(($u->first_lastname ?? '') . ' ' . ($u->second_lastname ?? '') . ' ' . ($u->name ?? ''));
    }

    private function docenteName(int $subjectId): string
    {
        $docenteId = $this->docentes[$subjectId] ?? null;
        if (!$docenteId) {
            return '';
        }
        $docente = DB::table('docentes')
            ->join('users', 'users.id', '=', 'docentes.user_id')
            ->leftJoin('degrees', 'degrees.id', '=', 'docentes.degree_id')
            ->where('docentes.id', $docenteId)
            ->select('users.name', 'users.first_lastname', 'users.second_lastname', 'degrees.abbreviation')
            ->first();

        if (!$docente) {
            return '';
        }
        $name = trim(($docente->first_lastname ?? '') . ' ' . ($docente->second_lastname ?? '') . ' ' . $docente->name);
        $abbreviation = $docente->abbreviation ?? '';
        return $abbreviation ? trim($abbreviation . ' ' . $name) : $name;
    }

    private function finalGrade(int $studentId, int $subjectId): ?float
    {
        $q = $this->qualifications->get($studentId . '_' . $subjectId);
        if (!$q) {
            return null;
        }
        if ($q->final_grade !== null) {
            return (float) $q->final_grade;
        }
        $grades = $this->columnGrades($studentId, $subjectId);
        if (count($grades) === 0) {
            return null;
        }
        return round(array_sum($grades) / count($grades), 2);
    }

    /**
     * Calculate theoretical average for a student in a subject.
     * Uses columns named 'examen' (theoretical evaluations).
     * Returns the average of those columns.
     */
    private function theoreticalAverage(int $studentId, int $subjectId): ?float
    {
        $q = $this->qualifications->get($studentId . '_' . $subjectId);
        if (!$q) {
            return null;
        }

        $columns = $this->columnsBySubject->get($subjectId);
        if (!$columns || $columns->isEmpty()) {
            return null;
        }

        // Theoretical columns: 'examen'
        $theoreticalColumns = $columns->filter(fn ($col) => in_array(strtolower($col->name), ['examen']));
        if ($theoreticalColumns->isEmpty()) {
            return null;
        }

        $sum = 0;
        $count = 0;
        foreach ($q->details as $detail) {
            if ($theoreticalColumns->contains('id', $detail->evaluation_column_id)) {
                $sum += $detail->grade;
                $count++;
            }
        }

        return $count > 0 ? round($sum / $count, 2) : null;
    }

    /**
     * Calculate practical average for a student in a subject.
     * Uses columns named 'practicas' or 'tarea' (practical evaluations).
     * Returns the average of those columns.
     */
    private function practicalAverage(int $studentId, int $subjectId): ?float
    {
        $q = $this->qualifications->get($studentId . '_' . $subjectId);
        if (!$q) {
            return null;
        }

        $columns = $this->columnsBySubject->get($subjectId);
        if (!$columns || $columns->isEmpty()) {
            return null;
        }

        // Practical columns: 'practicas', 'tarea'
        $practicalColumns = $columns->filter(fn ($col) => in_array(strtolower($col->name), ['practicas', 'tarea']));
        if ($practicalColumns->isEmpty()) {
            return null;
        }

        $sum = 0;
        $count = 0;
        foreach ($q->details as $detail) {
            if ($practicalColumns->contains('id', $detail->evaluation_column_id)) {
                $sum += $detail->grade;
                $count++;
            }
        }

        return $count > 0 ? round($sum / $count, 2) : null;
    }

    private function columnGrades(int $studentId, int $subjectId): array
    {
        $q = $this->qualifications->get($studentId . '_' . $subjectId);
        if (!$q) {
            return [];
        }
        $grades = [];
        foreach ($q->details as $detail) {
            $grades[$detail->evaluation_column_id] = $detail->grade;
        }
        return $grades;
    }

    private function observacion(int $studentId): string
    {
        $grades = [];
        foreach ($this->subjects as $subject) {
            $g = $this->finalGrade($studentId, $subject->id);
            if ($g !== null) {
                $grades[] = $g;
            }
        }
        if (count($grades) === 0) {
            return 'Abandono';
        }
        $avg = array_sum($grades) / count($grades);
        return $avg >= self::NOTA_MINIMA ? 'APROBADO' : 'REPROBADO';
    }

    private function observacionSubject(?float $final): string
    {
        if ($final === null) {
            return 'Abandono';
        }
        return $final >= self::NOTA_MINIMA ? 'APROBADO' : 'REPROBADO';
    }

    private function getRegimen(): string
    {
        $career = $this->parallel->course->career;
        return (int) $career->type === 2 ? 'SEMESTRAL' : 'ANUAL';
    }

    private function fillCentralizador(Worksheet $sheet): void
    {
        $course = $this->parallel->course;
        $career = $course->career;
        $totalCols = self::CENTRAL_LAST_SUBJECT_COL - self::CENTRAL_FIRST_SUBJECT_COL + 1;

        // Fill header info
        $sheet->setCellValue('E7', $this->year);
        $sheet->setCellValue('U5', mb_strtoupper($this->parallel->turno));
        $sheet->setCellValue('E10', mb_strtoupper($career->name));
        $sheet->setCellValue('E11', $this->getRegimen());
        $sheet->setCellValue('E12', mb_strtoupper($course->name) . ' - ' . $this->parallel->paralelo);

        // Fill subject siglas (row 7) and names (row 8)
        $used = 0;
        foreach ($this->subjects as $i => $subject) {
            if ($i >= $totalCols) {
                break;
            }
            $col = $this->colLetter(self::CENTRAL_FIRST_SUBJECT_COL + $i);
            $sheet->setCellValue($col . '7', $subject->sigla);
            $sheet->setCellValue($col . '8', $subject->name);
            $used++;
        }
        // Clear unused subject columns
        for ($c = self::CENTRAL_FIRST_SUBJECT_COL + $used; $c <= self::CENTRAL_LAST_SUBJECT_COL; $c++) {
            $col = $this->colLetter($c);
            $sheet->setCellValue($col . '7', '');
            $sheet->setCellValue($col . '8', '');
        }

        // Clear data rows
        $clearCols = ['C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'T', 'U'];
        for ($r = self::CENTRAL_FIRST_DATA_ROW; $r <= self::CENTRAL_LAST_DATA_ROW; $r++) {
            foreach ($clearCols as $col) {
                $sheet->setCellValue($col . $r, '');
            }
        }

        // Extend rows if needed
        $n = count($this->students);
        if (self::CENTRAL_FIRST_DATA_ROW + $n - 1 > self::CENTRAL_LAST_DATA_ROW) {
            $extra = self::CENTRAL_FIRST_DATA_ROW + $n - 1 - self::CENTRAL_LAST_DATA_ROW;
            $this->extendRows(
                $sheet,
                self::CENTRAL_LAST_DATA_ROW + 1,
                $extra,
                self::CENTRAL_LAST_DATA_ROW,
                self::CENTRAL_LAST_COL,
                ['D{r}:E{r}']
            );
        }

        // Fill student data rows
        $row = self::CENTRAL_FIRST_DATA_ROW;
        foreach ($this->students as $index => $student) {
            $sheet->setCellValue('C' . $row, $index + 1);
            $sheet->setCellValue('D' . $row, $this->studentFullName($student));
            $sheet->setCellValue('F' . $row, $student->user->ci ?? '');

            // Fill grades for each subject
            foreach ($this->subjects as $i => $subject) {
                if ($i >= $totalCols) {
                    break;
                }
                $col = $this->colLetter(self::CENTRAL_FIRST_SUBJECT_COL + $i);
                $final = $this->finalGrade($student->id, $subject->id);
                $sheet->setCellValue($col . $row, $final ?? '');
            }

            // Estado and Observaciones (leave blank)
            $sheet->setCellValue('T' . $row, $this->observacion($student->id));
            $sheet->setCellValue('U' . $row, '');

            $row++;
        }
    }

    private function fillDetalleMateria(Worksheet $sheet, Subject $subject): void
    {
        $course = $this->parallel->course;
        $career = $course->career;

        // Fill header info
        $sheet->setCellValue('E5', mb_strtoupper($career->name));
        $sheet->setCellValue('E6', $this->getRegimen());
        $sheet->setCellValue('E8', $subject->name);
        $sheet->setCellValue('J8', $subject->sigla);
        $sheet->setCellValue('E9', $this->docenteName($subject->id));
        $sheet->setCellValue('J9', mb_strtoupper($this->parallel->turno));
        $sheet->setCellValue('E10', mb_strtoupper($course->name) . ' - ' . $this->parallel->paralelo);
        $sheet->setCellValue('J10', $this->year);

        // Clear existing data rows
        for ($r = self::DETAIL_FIRST_DATA_ROW; $r <= self::DETAIL_LAST_DATA_ROW; $r++) {
            $sheet->setCellValue('C' . $r, '');
            $sheet->setCellValue('D' . $r, '');
            $sheet->setCellValue('F' . $r, '');
            $sheet->setCellValue('G' . $r, '');
            $sheet->setCellValue('H' . $r, '');
            $sheet->setCellValue('I' . $r, '');
            $sheet->setCellValue('J' . $r, '');
        }

        // Extend rows if needed
        $nStudents = count($this->students);
        if (self::DETAIL_FIRST_DATA_ROW + $nStudents - 1 > self::DETAIL_LAST_DATA_ROW) {
            $extra = self::DETAIL_FIRST_DATA_ROW + $nStudents - 1 - self::DETAIL_LAST_DATA_ROW;
            $this->extendRows(
                $sheet,
                self::DETAIL_LAST_DATA_ROW + 1,
                $extra,
                self::DETAIL_LAST_DATA_ROW,
                self::DETAIL_LAST_COL,
                ['D{r}:E{r}']
            );
        }

        // Fill student data
        $row = self::DETAIL_FIRST_DATA_ROW;
        $blueColor = '0000CC'; // Azul fuerte para mejor visibilidad

        foreach ($this->students as $index => $student) {
            $sheet->setCellValue('C' . $row, $index + 1);
            $sheet->setCellValue('D' . $row, $this->studentFullName($student));

            // Prom. Ev. Teórica = promedio teórico * 0.3
            $theoAvg = $this->theoreticalAverage($student->id, $subject->id);
            $theoWeighted = $theoAvg !== null ? round($theoAvg * self::WEIGHT_THEORY, 2) : null;
            $sheet->setCellValue('F' . $row, $theoWeighted !== null ? $theoWeighted : '');
            $sheet->getStyle('F' . $row)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($blueColor));

            // Prom. Eval. Práctica = promedio práctico * 0.7
            $pracAvg = $this->practicalAverage($student->id, $subject->id);
            $pracWeighted = $pracAvg !== null ? round($pracAvg * self::WEIGHT_PRACTICE, 2) : null;
            $sheet->setCellValue('G' . $row, $pracWeighted !== null ? $pracWeighted : '');
            $sheet->getStyle('G' . $row)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($blueColor));

            // Calificación Final = PromTeoricaPonderada + PromPracticaPonderada
            $final = ($theoWeighted !== null ? $theoWeighted : 0) + ($pracWeighted !== null ? $pracWeighted : 0);
            $final = $final > 0 ? $final : null;
            $sheet->setCellValue('H' . $row, $final !== null ? $final : '');
            $sheet->getStyle('H' . $row)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($blueColor));

            // Prueba de Recuperación
            $q = $this->qualifications->get($student->id . '_' . $subject->id);
            $recovery = $q?->recovery_grade;
            $sheet->setCellValue('I' . $row, $recovery !== null ? $recovery : '');
            $sheet->getStyle('I' . $row)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($blueColor));

            // Estado
            $sheet->setCellValue('J' . $row, $this->observacionSubject($final));
            $sheet->getStyle('J' . $row)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($blueColor));

            $row++;
        }
    }

    private function extendRows(Worksheet $sheet, int $insertBefore, int $count, int $sourceRow, string $lastCol, array $mergeRanges): void
    {
        $sheet->insertNewRowBefore($insertBefore, $count);
        for ($i = 0; $i < $count; $i++) {
            $newRow = $insertBefore + $i;
            $height = $sheet->getRowDimension($sourceRow)->getRowHeight();
            if ($height > 0) {
                $sheet->getRowDimension($newRow)->setRowHeight($height);
            }
            foreach (range('A', $lastCol) as $col) {
                $sheet->getStyle($col . $newRow)->applyFromArray($this->styleArrayFrom($sheet, $col . $sourceRow));
            }
            foreach ($mergeRanges as $range) {
                $sheet->mergeCells(str_replace('{r}', (string) $newRow, $range));
            }
        }
    }

    private function styleArrayFrom(Worksheet $sheet, string $cell): array
    {
        $st = $sheet->getStyle($cell);
        $f = $st->getFont();
        $fill = $st->getFill();
        $al = $st->getAlignment();
        $borders = $st->getBorders();

        $arr = [
            'font' => [
                'name' => $f->getName(),
                'size' => $f->getSize(),
                'bold' => $f->getBold(),
                'italic' => $f->getItalic(),
                'color' => ['rgb' => $f->getColor()->getRGB()],
            ],
            'fill' => [
                'fillType' => $fill->getFillType(),
                'startColor' => ['rgb' => $fill->getStartColor()->getRGB()],
                'endColor' => ['rgb' => $fill->getEndColor()->getRGB()],
            ],
            'alignment' => [
                'horizontal' => $al->getHorizontal(),
                'vertical' => $al->getVertical(),
                'wrapText' => $al->getWrapText(),
            ],
            'numberFormat' => ['formatCode' => $st->getNumberFormat()->getFormatCode()],
        ];

        $sides = ['top' => 'getTop', 'right' => 'getRight', 'bottom' => 'getBottom', 'left' => 'getLeft'];
        foreach ($sides as $side => $getter) {
            $border = $borders->$getter();
            $arr['borders'][$side] = [
                'borderStyle' => $border->getBorderStyle(),
                'color' => ['rgb' => $border->getColor()->getRGB()],
            ];
        }

        return $arr;
    }

    private function cleanupBrokenReferences(Spreadsheet $spreadsheet): void
    {
        $sheetNames = $spreadsheet->getSheetNames();

        foreach ($sheetNames as $name) {
            $sheet = $spreadsheet->getSheetByName($name);
            foreach ($sheet->getDataValidationCollection() as $cell => $validation) {
                $formula = $validation->getFormula1();
                if ($formula !== null && str_contains($formula, '#REF!')) {
                    $sheet->setDataValidation($cell, null);
                }
            }
        }

        $toRemove = [];
        foreach ($spreadsheet->getDefinedNames() as $definedName) {
            $refSheet = $this->referencedSheet($definedName->getValue());
            if ($refSheet !== null && !in_array($refSheet, $sheetNames, true)) {
                $toRemove[] = [$definedName->getName(), $definedName->getWorksheet()];
            }
        }
        foreach ($toRemove as [$name, $worksheet]) {
            $spreadsheet->removeDefinedName($name, $worksheet);
        }
    }

    private function referencedSheet(string $formula): ?string
    {
        if (preg_match("/^'([^']+)'!/", $formula, $m)) {
            return $m[1];
        }
        if (preg_match('/^([A-Za-z0-9_ ]+)!/', $formula, $m)) {
            return $m[1];
        }
        return null;
    }

    private function uniqueSheetTitle(Spreadsheet $spreadsheet, string $title): string
    {
        $title = preg_replace('/[:\\\\\/?\*\[\]]/', '-', trim($title));
        $title = $title === '' ? 'Materia' : mb_substr($title, 0, 31);
        $base = $title;
        $i = 2;
        while ($spreadsheet->sheetNameExists($title)) {
            $title = mb_substr($base, 0, 27) . ' ' . $i;
            $i++;
        }
        return $title;
    }

    private function colLetter(int $col): string
    {
        return Coordinate::stringFromColumnIndex($col);
    }

    private function colRange(int $from, int $to): array
    {
        $letters = [];
        for ($c = $from; $c <= $to; $c++) {
            $letters[] = $this->colLetter($c);
        }
        return $letters;
    }
}
