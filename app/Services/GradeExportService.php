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

    private const SHEET_CENTRALIZADOR = [
        'Mañana' => 'Centralizador',
        'Tarde' => 'Centralizador',
        'Noche' => 'Centralizador',
    ];

    private const SHEET_BASE_MATERIA = [
        'Mañana' => 'materia',
        'Tarde' => 'materia',
        'Noche' => 'materia',
    ];

    private const CENTRAL_FIRST_SUBJECT_COL = 7;   // G
    private const CENTRAL_LAST_SUBJECT_COL = 14;   // N
    private const CENTRAL_FIRST_DATA_ROW = 14;
    private const CENTRAL_LAST_DATA_ROW = 43;
    private const CENTRAL_LAST_COL = 'V';

    private const DETAIL_FIRST_EVAL_COL = 6;       // F
    private const DETAIL_OBS_COL = 10;             // J
    private const DETAIL_FIRST_DATA_ROW = 13;
    private const DETAIL_LAST_DATA_ROW = 47;
    private const DETAIL_LAST_COL = 'K';

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

        $turno = $this->parallel->turno;
        $central = $spreadsheet->getSheetByName(self::SHEET_CENTRALIZADOR[$turno] ?? '')
            ?: $spreadsheet->getSheetByNameOrThrow(self::SHEET_CENTRALIZADOR['Mañana']);
        $base = $spreadsheet->getSheetByName(self::SHEET_BASE_MATERIA[$turno] ?? '')
            ?: $spreadsheet->getSheetByNameOrThrow(self::SHEET_BASE_MATERIA['Mañana']);

        $central->setTitle('Centralizador');

        foreach ($spreadsheet->getSheetNames() as $name) {
            if ($name === 'Centralizador' || $name === $base->getTitle()) {
                continue;
            }
            $sheet = $spreadsheet->getSheetByName($name);
            if ($sheet) {
                $spreadsheet->removeSheetByIndex($spreadsheet->getIndex($sheet));
            }
        }

        $this->fillCentralizador($central);

        foreach ($this->subjects as $subject) {
            $sheet = $base->copy();
            $sheet->setTitle($this->uniqueSheetTitle($spreadsheet, $subject->sigla));
            $spreadsheet->addSheet($sheet);
            $this->fillDetalleMateria($sheet, $subject);
        }

        $spreadsheet->removeSheetByIndex($spreadsheet->getIndex($base));

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
        return $avg >= self::NOTA_MINIMA ? 'Aprobado' : 'Reprobado';
    }

    private function observacionSubject(?float $final): string
    {
        if ($final === null) {
            return 'Abandono';
        }
        return $final >= self::NOTA_MINIMA ? 'Aprobado' : 'Reprobado';
    }

    private function fillCentralizador(Worksheet $sheet): void
    {
        $course = $this->parallel->course;
        $career = $course->career;
        $totalCols = self::CENTRAL_LAST_SUBJECT_COL - self::CENTRAL_FIRST_SUBJECT_COL + 1;

        $sheet->setCellValue('E7', $this->year);
        $sheet->setCellValue('U5', mb_strtoupper($this->parallel->turno));
        $sheet->setCellValue('E10', mb_strtoupper($career->name));
        $sheet->setCellValue('E11', (int) $career->type === 2 ? 'SEMESTRAL' : 'ANUAL');
        $sheet->setCellValue('E12', mb_strtoupper($course->name) . ' - ' . $this->parallel->paralelo);

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
        for ($c = self::CENTRAL_FIRST_SUBJECT_COL + $used; $c <= self::CENTRAL_LAST_SUBJECT_COL; $c++) {
            $col = $this->colLetter($c);
            $sheet->setCellValue($col . '7', '');
            $sheet->setCellValue($col . '8', '');
        }

        $clearCols = ['C', 'D', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'V'];
        for ($r = self::CENTRAL_FIRST_DATA_ROW; $r <= self::CENTRAL_LAST_DATA_ROW; $r++) {
            foreach ($clearCols as $col) {
                $sheet->setCellValue($col . $r, '');
            }
        }

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

        $row = self::CENTRAL_FIRST_DATA_ROW;
        foreach ($this->students as $index => $student) {
            $sheet->setCellValue('C' . $row, $index + 1);
            $sheet->setCellValue('D' . $row, $this->studentFullName($student));
            $sheet->setCellValue('F' . $row, $student->user->ci ?? '');

            foreach ($this->subjects as $i => $subject) {
                if ($i >= $totalCols) {
                    break;
                }
                $col = $this->colLetter(self::CENTRAL_FIRST_SUBJECT_COL + $i);
                $sheet->setCellValue($col . $row, $this->finalGrade($student->id, $subject->id));
            }

            $sheet->setCellValue('V' . $row, $this->observacion($student->id));
            $row++;
        }
    }

    private function fillDetalleMateria(Worksheet $sheet, Subject $subject): void
    {
        $course = $this->parallel->course;
        $career = $course->career;
        $columns = $this->columnsBySubject->get($subject->id);
        $columns = $columns ? $columns->values() : collect();

        $firstEval = self::DETAIL_FIRST_EVAL_COL;
        $n = $columns->count();
        $finalCol = $firstEval + $n;
        $obsCol = self::DETAIL_OBS_COL;

        if ($finalCol > $obsCol) {
            $insert = $finalCol - $obsCol;
            $sheet->insertNewColumnBefore($this->colLetter($obsCol), $insert);
            $obsCol += $insert;
            $this->copyDetailColumnStyles($sheet, $insert);
        }

        $sheet->setCellValue('E5', mb_strtoupper($career->name));
        $sheet->setCellValue('E6', '');
        $sheet->setCellValue('E7', '');
        $sheet->setCellValue('E8', $subject->name);
        $sheet->setCellValue('J8', $subject->sigla);
        $sheet->setCellValue('E9', $this->docenteName($subject->id));
        $sheet->setCellValue('E10', mb_strtoupper($course->name));
        $sheet->setCellValue('J10', $this->year);

        for ($i = 0; $i < $n; $i++) {
            $sheet->setCellValue($this->colLetter($firstEval + $i) . '12', $columns[$i]->name);
        }
        $sheet->setCellValue($this->colLetter($finalCol) . '12', 'Calificación Final');
        $sheet->setCellValue($this->colLetter($obsCol) . '12', 'Observación');
        for ($c = $finalCol + 1; $c < $obsCol; $c++) {
            $sheet->setCellValue($this->colLetter($c) . '12', '');
        }

        $dataCols = array_merge(['C', 'D'], $this->colRange($firstEval, $obsCol));
        for ($r = self::DETAIL_FIRST_DATA_ROW; $r <= self::DETAIL_LAST_DATA_ROW; $r++) {
            foreach ($dataCols as $col) {
                $sheet->setCellValue($col . $r, '');
            }
        }

        $nStudents = count($this->students);
        if (self::DETAIL_FIRST_DATA_ROW + $nStudents - 1 > self::DETAIL_LAST_DATA_ROW) {
            $extra = self::DETAIL_FIRST_DATA_ROW + $nStudents - 1 - self::DETAIL_LAST_DATA_ROW;
            $this->extendRows(
                $sheet,
                self::DETAIL_LAST_DATA_ROW + 1,
                $extra,
                self::DETAIL_LAST_DATA_ROW,
                self::DETAIL_LAST_COL,
                ['D{r}:E{r}', $this->colLetter($obsCol) . '{r}:' . $this->colLetter($obsCol + 1) . '{r}']
            );
        }

        $row = self::DETAIL_FIRST_DATA_ROW;
        foreach ($this->students as $index => $student) {
            $sheet->setCellValue('C' . $row, $index + 1);
            $sheet->setCellValue('D' . $row, $this->studentFullName($student));

            $colGrades = $this->columnGrades($student->id, $subject->id);
            for ($i = 0; $i < $n; $i++) {
                $cell = $this->colLetter($firstEval + $i) . $row;
                $grade = $colGrades[$columns[$i]->id] ?? null;
                $sheet->setCellValue($cell, $grade !== null ? $grade : '');
            }

            $final = $this->finalGrade($student->id, $subject->id);
            $sheet->setCellValue($this->colLetter($finalCol) . $row, $final);
            $sheet->setCellValue($this->colLetter($obsCol) . $row, $this->observacionSubject($final));
            $row++;
        }
    }

    private function copyDetailColumnStyles(Worksheet $sheet, int $insert): void
    {
        $src = $this->colLetter(self::DETAIL_FIRST_EVAL_COL);
        for ($c = self::DETAIL_OBS_COL; $c < self::DETAIL_OBS_COL + $insert; $c++) {
            $dest = $this->colLetter($c);
            $sheet->getColumnDimension($dest)->setWidth($sheet->getColumnDimension($src)->getWidth());
            for ($r = 12; $r <= self::DETAIL_LAST_DATA_ROW; $r++) {
                $sheet->getStyle($dest . $r)->applyFromArray($this->styleArrayFrom($sheet, $src . $r));
            }
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
