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
    private const DETAIL_FIRST_DATA_ROW = 10;
    private const DETAIL_LAST_DATA_ROW = 44;
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
     * Uses columns with type='teorica' and weighted average (col.weight).
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

        $theoreticalColumns = $columns->filter(fn ($col) => $col->type === 'teorica');
        if ($theoreticalColumns->isEmpty()) {
            return null;
        }

        $sum = 0;
        $weightSum = 0;
        foreach ($q->details as $detail) {
            $col = $theoreticalColumns->firstWhere('id', $detail->evaluation_column_id);
            if ($col && $detail->grade !== null) {
                $sum += $detail->grade * $col->weight;
                $weightSum += $col->weight;
            }
        }

        return $weightSum > 0 ? round($sum / $weightSum, 2) : null;
    }

    /**
     * Calculate practical average for a student in a subject.
     * Uses columns with type='practica' and weighted average (col.weight).
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

        $practicalColumns = $columns->filter(fn ($col) => $col->type === 'practica');
        if ($practicalColumns->isEmpty()) {
            return null;
        }

        $sum = 0;
        $weightSum = 0;
        foreach ($q->details as $detail) {
            $col = $practicalColumns->firstWhere('id', $detail->evaluation_column_id);
            if ($col && $detail->grade !== null) {
                $sum += $detail->grade * $col->weight;
                $weightSum += $col->weight;
            }
        }

        return $weightSum > 0 ? round($sum / $weightSum, 2) : null;
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

        // Colors from template
        $tealBg = '31869B';
        $lightBlueBg = 'DBEFF4';
        $whiteFont = 'FFFFFF';
        $lightBlueFont = 'B6DEE8';
        $darkTealFont = '215967';
        $blackFont = '000000';

        // Apply header row 7 styles (teal background, white/light blue font)
        $headerRow7 = ['C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'T', 'U'];
        foreach ($headerRow7 as $col) {
            $style = $sheet->getStyle($col . '7');
            $style->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($tealBg);
            $style->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($whiteFont))->setBold(true)->setName('Calibri')->setSize(11);
            $style->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        }

        // Apply row 8 styles (teal background, light blue font for subject names)
        for ($c = self::CENTRAL_FIRST_SUBJECT_COL; $c <= self::CENTRAL_LAST_SUBJECT_COL; $c++) {
            $col = $this->colLetter($c);
            $style = $sheet->getStyle($col . '8');
            $style->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($tealBg);
            $style->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($lightBlueFont))->setBold(true)->setName('Calibri')->setSize(10);
            $style->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        }

        // Apply rows 9-12 styles (light blue background, dark teal font)
        $labelRows = ['9', '10', '11', '12'];
        foreach ($labelRows as $r) {
            $style = $sheet->getStyle('C' . $r);
            $style->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($tealBg);
            $style->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($whiteFont))->setBold(true)->setName('Calibri')->setSize(11);

            $style = $sheet->getStyle('E' . $r);
            $style->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($lightBlueBg);
            $style->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($darkTealFont))->setName('Calibri')->setSize(11);
        }

        // Apply row 13 styles (light blue background, dark teal font for column headers)
        $dataHeaderCols = ['C', 'D', 'F'];
        foreach ($dataHeaderCols as $col) {
            $style = $sheet->getStyle($col . '13');
            $style->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($lightBlueBg);
            $style->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($darkTealFont))->setBold(true)->setName('Calibri')->setSize(11);
            $style->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        }

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
                $sheet->getStyle($col . $row)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($blackFont))->setName('Arial')->setSize(11);
                $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            }

            // Estado and Observaciones (leave blank)
            $sheet->setCellValue('T' . $row, $this->observacion($student->id));
            $sheet->setCellValue('U' . $row, '');

            // Style for row number and name
            $sheet->getStyle('C' . $row)->getFont()->setName('Arial')->setSize(11);
            $sheet->getStyle('C' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('D' . $row)->getFont()->setName('Arial Narrow')->setSize(11);

            $row++;
        }
    }

    private function fillDetalleMateria(Worksheet $sheet, Subject $subject): void
    {
        $course = $this->parallel->course;
        $career = $course->career;

        // Colors from the image
        $darkBg = '333333';       // Dark gray for labels
        $whiteFont = 'FFFFFF';    // White text
        $blackFont = '000000';    // Black text
        $greenBg = '76923C';      // Green for 1° parcial header
        $blueBg = '4472C4';       // Blue for 2° parcial header
        $yellowBg = 'FFC000';     // Yellow for nota final header
        $lightBlueBg = 'D6EAF8';  // Light blue for teorica sub-header
        $lightGreenBg = 'D5F5E3'; // Light green for practica sub-header
        $lightYellowBg = 'FEF9E7'; // Light yellow for final sub-header
        $lightGrayBg = 'F2F2F2';  // Light gray for recuperación

        // Set column widths
        $sheet->getDefaultColumnDimension()->setWidth(12);
        $sheet->getColumnDimension('A')->setWidth(2);
        $sheet->getColumnDimension('B')->setWidth(2);
        $sheet->getColumnDimension('C')->setWidth(6);   // Nr
        $sheet->getColumnDimension('D')->setWidth(35);  // Apellidos y Nombres
        $sheet->getColumnDimension('E')->setWidth(3);   // Separator
        $sheet->getColumnDimension('F')->setWidth(15);  // Prom. Ev. Teórica
        $sheet->getColumnDimension('G')->setWidth(15);  // Prom. Eval. Práctica
        $sheet->getColumnDimension('H')->setWidth(15);  // Calificación Final
        $sheet->getColumnDimension('I')->setWidth(15);  // Prueba de Recuperación
        $sheet->getColumnDimension('J')->setWidth(15);  // Estado

        // === ROWS 2-7: Header info with dark labels ===
        // Row 2: CARRERA
        $sheet->mergeCells('C2:D2');
        $sheet->setCellValue('C2', 'CARRERA:');
        $sheet->getStyle('C2')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($darkBg);
        $sheet->getStyle('C2')->getFont()->setBold(true)->setName('Calibri')->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($whiteFont));
        $sheet->getStyle('C2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->mergeCells('E2:H2');
        $sheet->setCellValue('E2', mb_strtoupper($career->name));
        $sheet->getStyle('E2')->getFont()->setName('Calibri')->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($blackFont));
        $sheet->getStyle('E2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

        // Row 3: MENCIÓN
        $sheet->mergeCells('C3:D3');
        $sheet->setCellValue('C3', 'MENCIÓN:');
        $sheet->getStyle('C3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($darkBg);
        $sheet->getStyle('C3')->getFont()->setBold(true)->setName('Calibri')->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($whiteFont));
        $sheet->getStyle('C3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->mergeCells('E3:H3');
        $sheet->setCellValue('E3', '');

        // Row 4: ÁREA
        $sheet->mergeCells('C4:D4');
        $sheet->setCellValue('C4', 'ÁREA:');
        $sheet->getStyle('C4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($darkBg);
        $sheet->getStyle('C4')->getFont()->setBold(true)->setName('Calibri')->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($whiteFont));
        $sheet->getStyle('C4')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->mergeCells('E4:H4');
        $sheet->setCellValue('E4', mb_strtoupper($career->name));

        // Row 5: MATERIA
        $sheet->mergeCells('C5:D5');
        $sheet->setCellValue('C5', 'MATERIA:');
        $sheet->getStyle('C5')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($darkBg);
        $sheet->getStyle('C5')->getFont()->setBold(true)->setName('Calibri')->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($whiteFont));
        $sheet->getStyle('C5')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->mergeCells('E5:H5');
        $sheet->setCellValue('E5', $subject->name);
        $sheet->getStyle('E5')->getFont()->setName('Calibri')->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($blackFont));
        $sheet->getStyle('E5')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

        // Row 5 right side: SIGLA
        $sheet->setCellValue('I5', 'SIGLA:');
        $sheet->getStyle('I5')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($darkBg);
        $sheet->getStyle('I5')->getFont()->setBold(true)->setName('Calibri')->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($whiteFont));
        $sheet->getStyle('I5')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->setCellValue('J5', $subject->sigla);
        $sheet->getStyle('J5')->getFont()->setName('Calibri')->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($blackFont));
        $sheet->getStyle('J5')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

        // Row 6: DOCENTE
        $sheet->mergeCells('C6:D6');
        $sheet->setCellValue('C6', 'DOCENTE:');
        $sheet->getStyle('C6')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($darkBg);
        $sheet->getStyle('C6')->getFont()->setBold(true)->setName('Calibri')->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($whiteFont));
        $sheet->getStyle('C6')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->mergeCells('E6:H6');
        $sheet->setCellValue('E6', $this->docenteName($subject->id));
        $sheet->getStyle('E6')->getFont()->setName('Calibri')->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($blackFont));
        $sheet->getStyle('E6')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

        // Row 6 right side: GESTIÓN
        $sheet->setCellValue('I6', 'GESTIÓN:');
        $sheet->getStyle('I6')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($darkBg);
        $sheet->getStyle('I6')->getFont()->setBold(true)->setName('Calibri')->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($whiteFont));
        $sheet->getStyle('I6')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->setCellValue('J6', $this->year);
        $sheet->getStyle('J6')->getFont()->setName('Calibri')->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($blackFont));
        $sheet->getStyle('J6')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

        // Row 7: AÑO/SEMESTRE
        $sheet->mergeCells('C7:D7');
        $sheet->setCellValue('C7', 'AÑO/SEMESTRE:');
        $sheet->getStyle('C7')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($darkBg);
        $sheet->getStyle('C7')->getFont()->setBold(true)->setName('Calibri')->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($whiteFont));
        $sheet->getStyle('C7')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->mergeCells('E7:H7');
        $sheet->setCellValue('E7', mb_strtoupper($course->name) . ' - ' . $this->parallel->paralelo);
        $sheet->getStyle('E7')->getFont()->setName('Calibri')->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($blackFont));
        $sheet->getStyle('E7')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

        // Insert logo
        $logoPath = public_path('images/logo.png');
        if (file_exists($logoPath)) {
            $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
            $drawing->setName('Logo NOVELL');
            $drawing->setDescription('Logo Instituto NOVELL');
            $drawing->setPath($logoPath);
            $drawing->setCoordinates('I2');
            $drawing->setOffsetX(10);
            $drawing->setOffsetY(5);
            $drawing->setWidth(180);
            $drawing->setHeight(80);
            $drawing->setWorksheet($sheet);
        }

        // === ROW 8: Column group headers (colored backgrounds) ===
        // "CALIFICACIONES" spanning all columns
        $sheet->mergeCells('C8:J8');
        $sheet->setCellValue('C8', 'CALIFICACIONES');
        $sheet->getStyle('C8')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($darkBg);
        $sheet->getStyle('C8')->getFont()->setBold(true)->setName('Calibri')->setSize(12)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($whiteFont));
        $sheet->getStyle('C8')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension('8')->setRowHeight(25);

        // === ROW 9: Sub-headers with colors ===
        // C9: Nr
        $sheet->setCellValue('C9', 'Nr');
        $sheet->getStyle('C9')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($darkBg);
        $sheet->getStyle('C9')->getFont()->setBold(true)->setName('Arial')->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($whiteFont));
        $sheet->getStyle('C9')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension('9')->setRowHeight(30);

        // D9: Apellidos y Nombres
        $sheet->setCellValue('D9', 'Apellidos y Nombres');
        $sheet->getStyle('D9')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($darkBg);
        $sheet->getStyle('D9')->getFont()->setBold(true)->setName('Arial')->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($whiteFont));
        $sheet->getStyle('D9')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

        // F9: Prom. Ev Teórica (light blue)
        $sheet->setCellValue('F9', "Prom. Ev\nTeórica");
        $sheet->getStyle('F9')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($lightBlueBg);
        $sheet->getStyle('F9')->getFont()->setBold(true)->setName('Arial')->setSize(9)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($blackFont));
        $sheet->getStyle('F9')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)->setWrapText(true);

        // G9: Prom. Eval. Práctica (light green)
        $sheet->setCellValue('G9', "Prom. Eval\nPráctica");
        $sheet->getStyle('G9')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($lightGreenBg);
        $sheet->getStyle('G9')->getFont()->setBold(true)->setName('Arial')->setSize(9)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($blackFont));
        $sheet->getStyle('G9')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)->setWrapText(true);

        // H9: Calificación Final (light yellow)
        $sheet->setCellValue('H9', "Calificación\nFinal");
        $sheet->getStyle('H9')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($lightYellowBg);
        $sheet->getStyle('H9')->getFont()->setBold(true)->setName('Arial')->setSize(9)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($blackFont));
        $sheet->getStyle('H9')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)->setWrapText(true);

        // I9: Prueba de Recuperación (light gray)
        $sheet->setCellValue('I9', "Prueba de\nRecuper.");
        $sheet->getStyle('I9')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($lightGrayBg);
        $sheet->getStyle('I9')->getFont()->setBold(true)->setName('Arial')->setSize(9)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($blackFont));
        $sheet->getStyle('I9')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)->setWrapText(true);

        // J9: Observación (dark gray)
        $sheet->setCellValue('J9', 'Observación');
        $sheet->getStyle('J9')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($darkBg);
        $sheet->getStyle('J9')->getFont()->setBold(true)->setName('Arial')->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($whiteFont));
        $sheet->getStyle('J9')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

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
        $theoryWeight = $subject->theory_weight ?? 0.3;
        $practiceWeight = $subject->practice_weight ?? 0.7;

        // Alternate row colors
        $evenRowBg = 'F8F9FA';

        foreach ($this->students as $index => $student) {
            // Alternate row background
            if ($index % 2 === 1) {
                foreach (['C', 'D', 'F', 'G', 'H', 'I', 'J'] as $col) {
                    $sheet->getStyle($col . $row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($evenRowBg);
                }
            }

            $sheet->setCellValue('C' . $row, $index + 1);
            $sheet->setCellValue('D' . $row, $this->studentFullName($student));

            // Prom. Ev. Teórica = promedio teórico * theory_weight
            $theoAvg = $this->theoreticalAverage($student->id, $subject->id);
            $theoWeighted = $theoAvg !== null ? round($theoAvg * $theoryWeight, 2) : null;
            $sheet->setCellValue('F' . $row, $theoWeighted !== null ? $theoWeighted : '');

            // Prom. Eval. Práctica = promedio práctico * practice_weight
            $pracAvg = $this->practicalAverage($student->id, $subject->id);
            $pracWeighted = $pracAvg !== null ? round($pracAvg * $practiceWeight, 2) : null;
            $sheet->setCellValue('G' . $row, $pracWeighted !== null ? $pracWeighted : '');

            // Calificación Final = PromTeoricaPonderada + PromPracticaPonderada
            $final = ($theoWeighted !== null ? $theoWeighted : 0) + ($pracWeighted !== null ? $pracWeighted : 0);
            $final = $final > 0 ? $final : null;
            $sheet->setCellValue('H' . $row, $final !== null ? $final : '');

            // Prueba de Recuperación
            $q = $this->qualifications->get($student->id . '_' . $subject->id);
            $recovery = $q?->recovery_grade;
            $sheet->setCellValue('I' . $row, $recovery !== null ? $recovery : '');

            // Estado
            $sheet->setCellValue('J' . $row, $this->observacionSubject($final));

            // Apply data row styles
            $sheet->getStyle('C' . $row)->getFont()->setName('Arial')->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($blackFont));
            $sheet->getStyle('C' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            $sheet->getStyle('D' . $row)->getFont()->setName('Arial Narrow')->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($blackFont));
            $sheet->getStyle('D' . $row)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            $sheet->getStyle('F' . $row)->getFont()->setName('Arial')->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($blackFont));
            $sheet->getStyle('F' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            $sheet->getStyle('G' . $row)->getFont()->setName('Arial')->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($blackFont));
            $sheet->getStyle('G' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            $sheet->getStyle('H' . $row)->getFont()->setName('Arial')->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($blackFont));
            $sheet->getStyle('H' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            $sheet->getStyle('I' . $row)->getFont()->setName('Arial')->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($blackFont));
            $sheet->getStyle('I' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            $sheet->getStyle('J' . $row)->getFont()->setName('Agency FB')->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($blackFont));
            $sheet->getStyle('J' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

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
