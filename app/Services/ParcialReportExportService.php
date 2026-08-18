<?php

namespace App\Services;

use App\Models\EvaluationColumn;
use App\Models\Parallel;
use App\Models\Qualification;
use App\Models\Student;
use App\Models\Subject;
use App\Models\StudentParallel;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ParcialReportExportService
{
    private const NOTA_MINIMA = 61;

    // Colors
    private const COLOR_DARK = '333333';
    private const COLOR_WHITE = 'FFFFFF';
    private const COLOR_BLACK = '000000';
    private const COLOR_GREEN = '76923C';
    private const COLOR_BLUE = '4472C4';
    private const COLOR_YELLOW = 'FFC000';
    private const COLOR_LIGHT_BLUE = 'D6EAF8';
    private const COLOR_LIGHT_GREEN = 'D5F5E3';
    private const COLOR_LIGHT_YELLOW = 'FEF9E7';
    private const COLOR_LIGHT_GRAY = 'F2F2F2';
    private const COLOR_ALT_ROW = 'F8F9FA';

    // Columns: C(3)=Nr, D(4)=Nombre, E(5)=1°Teo, F(6)=1°Pra, G(7)=1°Fin,
    //          H(8)=2°Teo, I(9)=2°Pra, J(10)=2°Fin, K(11)=Prom, L(12)=Recup, M(13)=Obs
    private const COL_NR = 3;        // C
    private const COL_NAME = 4;      // D
    private const COL_FIRST_DATA = 5; // E
    private const COL_PROMEDIO = 11;  // K
    private const COL_RECUPERACION = 12; // L
    private const COL_OBSERVACION = 13;  // M

    private const DATA_START_ROW = 10;
    private const HEADER_ROW_8 = 8;
    private const HEADER_ROW_9 = 9;

    private Subject $subject;
    private Parallel $parallel;
    private array $columnsByParcial;
    private array $students;
    private $qualifications;
    private Worksheet $sheet;

    public function generate(int $subjectId, int $parallelId): string
    {
        $this->subject = Subject::findOrFail($subjectId);
        $this->parallel = Parallel::with('course.career')->findOrFail($parallelId);
        $this->loadData();

        $spreadsheet = new Spreadsheet();
        $this->sheet = $spreadsheet->getActiveSheet();
        $this->sheet->setTitle('Informe Parciales');

        $this->setupColumns();
        $this->fillHeader();
        $this->fillParcialHeaders();
        $this->fillSubHeaders();
        $this->fillData();
        $this->fillSummary();
        $this->insertLogo();

        $writer = new Xlsx($spreadsheet);
        $path = tempnam(sys_get_temp_dir(), 'parcial_report_') . '.xlsx';
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function loadData(): void
    {
        $parallelId = $this->parallel->id;
        $subjectId = $this->subject->id;

        $columns = EvaluationColumn::where('subject_id', $subjectId)
            ->where('parallel_id', $parallelId)
            ->orderBy('parcial')
            ->orderBy('order')
            ->get();

        $this->columnsByParcial = $columns->groupBy('parcial')->toArray();

        $studentIds = StudentParallel::where('parallel_id', $parallelId)
            ->where('status', true)
            ->pluck('student_id');

        $this->students = Student::whereIn('id', $studentIds)
            ->with('user')
            ->get()
            ->sortBy(fn($s) => trim(($s->user->first_lastname ?? '') . ' ' . ($s->user->second_lastname ?? '') . ' ' . ($s->user->name ?? '')))
            ->values()
            ->all();

        $this->qualifications = Qualification::where('subject_id', $subjectId)
            ->where('course_id', $this->parallel->course_id)
            ->where('parallel_id', $parallelId)
            ->with('details')
            ->get()
            ->keyBy('student_id');
    }

    private function setupColumns(): void
    {
        $this->sheet->getDefaultColumnDimension()->setWidth(12);
        $this->sheet->getColumnDimension('A')->setWidth(2);
        $this->sheet->getColumnDimension('B')->setWidth(2);
        $this->sheet->getColumnDimension('C')->setWidth(6);
        $this->sheet->getColumnDimension('D')->setWidth(35);
        $this->sheet->getColumnDimension('E')->setWidth(3);
        $this->sheet->getColumnDimension('F')->setWidth(15);
        $this->sheet->getColumnDimension('G')->setWidth(15);
        $this->sheet->getColumnDimension('H')->setWidth(15);
        $this->sheet->getColumnDimension('I')->setWidth(15);
        $this->sheet->getColumnDimension('J')->setWidth(15);
        $this->sheet->getColumnDimension('K')->setWidth(15);
        $this->sheet->getColumnDimension('L')->setWidth(15);
        $this->sheet->getColumnDimension('M')->setWidth(15);
    }

    private function fillHeader(): void
    {
        $sheet = $this->sheet;
        $subject = $this->subject;
        $parallel = $this->parallel;
        $career = $parallel->course?->career;

        $labels = [
            2 => 'CARRERA:',
            3 => 'MENCIÓN:',
            4 => 'ÁREA:',
            5 => 'MATERIA:',
            6 => 'DOCENTE:',
            7 => 'AÑO/SEMESTRE:',
        ];

        $values = [
            2 => mb_strtoupper($career?->name ?? ''),
            3 => '',
            4 => mb_strtoupper($career?->name ?? ''),
            5 => $subject->name,
            6 => $this->docenteName(),
            7 => mb_strtoupper($this->parallel->course?->name ?? '') . ' - ' . $this->parallel->paralelo,
        ];

        foreach ($labels as $r => $label) {
            $sheet->mergeCells("C{$r}:D{$r}");
            $sheet->setCellValue("C{$r}", $label);
            $sheet->getStyle("C{$r}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_DARK);
            $sheet->getStyle("C{$r}")->getFont()->setBold(true)->setName('Calibri')->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_WHITE));
            $sheet->getStyle("C{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            $sheet->mergeCells("E{$r}:H{$r}");
            $sheet->setCellValue("E{$r}", $values[$r]);
            $sheet->getStyle("E{$r}")->getFont()->setName('Calibri')->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_BLACK));
            $sheet->getStyle("E{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        }

        // SIGLA (row 5)
        $sheet->setCellValue('I5', 'SIGLA:');
        $sheet->getStyle('I5')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_DARK);
        $sheet->getStyle('I5')->getFont()->setBold(true)->setName('Calibri')->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_WHITE));
        $sheet->getStyle('I5')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->setCellValue('J5', $subject->sigla);
        $sheet->getStyle('J5')->getFont()->setName('Calibri')->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_BLACK));
        $sheet->getStyle('J5')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

        // GESTIÓN (row 6)
        $sheet->setCellValue('I6', 'GESTIÓN:');
        $sheet->getStyle('I6')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_DARK);
        $sheet->getStyle('I6')->getFont()->setBold(true)->setName('Calibri')->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_WHITE));
        $sheet->getStyle('I6')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->setCellValue('J6', $this->year());
        $sheet->getStyle('J6')->getFont()->setName('Calibri')->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_BLACK));
        $sheet->getStyle('J6')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
    }

    private function fillParcialHeaders(): void
    {
        $sheet = $this->sheet;
        $numParciales = $this->subject->num_parciales;

        // C8:D8 dark
        $sheet->mergeCells('C8:D8');
        $sheet->setCellValue('C8', '');
        $sheet->getStyle('C8')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_DARK);
        $sheet->getRowDimension(8)->setRowHeight(25);

        $col = self::COL_FIRST_DATA; // E=5

        for ($p = 1; $p <= $numParciales; $p++) {
            $startLetter = Coordinate::stringFromColumnIndex($col);
            $endLetter = Coordinate::stringFromColumnIndex($col + 2);
            $sheet->mergeCells("{$startLetter}8:{$endLetter}8");
            $sheet->setCellValue("{$startLetter}8", $p . '° PARCIAL');
            $bg = $p === 1 ? self::COLOR_GREEN : self::COLOR_BLUE;
            $sheet->getStyle("{$startLetter}8")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($bg);
            $sheet->getStyle("{$startLetter}8")->getFont()->setBold(true)->setName('Arial')->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_WHITE));
            $sheet->getStyle("{$startLetter}8")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $col += 3;
        }

        // NOTA FINAL
        $nfLetter = Coordinate::stringFromColumnIndex($col);
        $sheet->setCellValue("{$nfLetter}8", 'NOTA FINAL');
        $sheet->getStyle("{$nfLetter}8")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_YELLOW);
        $sheet->getStyle("{$nfLetter}8")->getFont()->setBold(true)->setName('Arial')->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_BLACK));
        $sheet->getStyle("{$nfLetter}8")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $col++;

        // Prueba de Recuper. + Observación
        $recLetter = Coordinate::stringFromColumnIndex($col);
        $sheet->setCellValue("{$recLetter}8", 'Prueba de Recuper.');
        $sheet->getStyle("{$recLetter}8")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_DARK);
        $sheet->getStyle("{$recLetter}8")->getFont()->setBold(true)->setName('Arial')->setSize(9)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_WHITE));
        $sheet->getStyle("{$recLetter}8")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $col++;

        $obsLetter = Coordinate::stringFromColumnIndex($col);
        $sheet->setCellValue("{$obsLetter}8", 'Observación');
        $sheet->getStyle("{$obsLetter}8")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_DARK);
        $sheet->getStyle("{$obsLetter}8")->getFont()->setBold(true)->setName('Arial')->setSize(9)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_WHITE));
        $sheet->getStyle("{$obsLetter}8")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
    }

    private function fillSubHeaders(): void
    {
        $sheet = $this->sheet;
        $numParciales = $this->subject->num_parciales;

        // C9: Nr
        $sheet->setCellValue('C9', 'Nr');
        $this->applyDarkHeader('C9');
        $sheet->getRowDimension(9)->setRowHeight(35);

        // D9: Apellidos y Nombres
        $sheet->setCellValue('D9', 'Apellidos y Nombres');
        $this->applyDarkHeader('D9');

        $col = self::COL_FIRST_DATA; // E=5

        for ($p = 1; $p <= $numParciales; $p++) {
            $theoLetter = Coordinate::stringFromColumnIndex($col);
            $praLetter = Coordinate::stringFromColumnIndex($col + 1);
            $finLetter = Coordinate::stringFromColumnIndex($col + 2);

            $sheet->setCellValue("{$theoLetter}9", "Prom. Ev\nTeórica");
            $this->applyColoredSubHeader("{$theoLetter}9", self::COLOR_LIGHT_BLUE);

            $sheet->setCellValue("{$praLetter}9", "Prom. Eval\nPráctica");
            $this->applyColoredSubHeader("{$praLetter}9", self::COLOR_LIGHT_GREEN);

            $sheet->setCellValue("{$finLetter}9", "Calificación\nFinal");
            $this->applyColoredSubHeader("{$finLetter}9", self::COLOR_LIGHT_YELLOW);

            $col += 3;
        }

        // K9: PROMEDIO
        $promLetter = Coordinate::stringFromColumnIndex($col);
        $sheet->setCellValue("{$promLetter}9", "PROMEDIO 1° Y 2°\nPARCIAL");
        $this->applyDarkHeader("{$promLetter}9");
        $col++;

        // L9: Prueba de Recuperación
        $recLetter = Coordinate::stringFromColumnIndex($col);
        $sheet->setCellValue("{$recLetter}9", "Prueba de\nRecuper.");
        $this->applyColoredSubHeader("{$recLetter}9", self::COLOR_LIGHT_GRAY);
        $col++;

        // M9: Observación
        $obsLetter = Coordinate::stringFromColumnIndex($col);
        $sheet->setCellValue("{$obsLetter}9", 'Observación');
        $this->applyDarkHeader("{$obsLetter}9");
    }

    private function applyDarkHeader(string $cell): void
    {
        $sheet = $this->sheet;
        $sheet->getStyle($cell)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_DARK);
        $sheet->getStyle($cell)->getFont()->setBold(true)->setName('Arial')->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_WHITE));
        $sheet->getStyle($cell)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)->setWrapText(true);
    }

    private function applyColoredSubHeader(string $cell, string $bg): void
    {
        $sheet = $this->sheet;
        $sheet->getStyle($cell)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($bg);
        $sheet->getStyle($cell)->getFont()->setBold(true)->setName('Arial')->setSize(9)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_BLACK));
        $sheet->getStyle($cell)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)->setWrapText(true);
    }

    private function fillData(): void
    {
        $sheet = $this->sheet;
        $subject = $this->subject;
        $parciales = $this->columnsByParcial;
        $numParciales = $subject->num_parciales;
        $row = self::DATA_START_ROW;
        $index = 1;

        foreach ($this->students as $studentIdx => $student) {
            $qual = $this->qualifications->get($student->id);

            // Alternate row background
            if ($studentIdx % 2 === 1) {
                foreach (['C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M'] as $colLetter) {
                    $sheet->getStyle("{$colLetter}{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_ALT_ROW);
                }
            }

            // Nr
            $sheet->setCellValue("C{$row}", $index);
            $sheet->getStyle("C{$row}")->getFont()->setName('Arial')->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_BLACK));
            $sheet->getStyle("C{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            // Name
            $fullName = trim(($student->user->first_lastname ?? '') . ' ' . ($student->user->second_lastname ?? '') . ' ' . ($student->user->name ?? ''));
            $sheet->setCellValue("D{$row}", $fullName);
            $sheet->getStyle("D{$row}")->getFont()->setName('Arial Narrow')->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_BLACK));
            $sheet->getStyle("D{$row}")->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            $col = self::COL_FIRST_DATA;
            $parcialFinals = [];

            for ($p = 1; $p <= $numParciales; $p++) {
                $parcialCols = $parciales[$p] ?? [];
                $theoSum = 0;
                $theoWeightSum = 0;
                $praSum = 0;
                $praWeightSum = 0;

                foreach ($parcialCols as $colDef) {
                    $detail = $qual?->details->firstWhere('evaluation_column_id', $colDef['id']);
                    $grade = $detail?->grade;

                    if ($grade !== null) {
                        if ($colDef['type'] === 'teorica') {
                            $theoSum += $grade * $colDef['weight'];
                            $theoWeightSum += $colDef['weight'];
                        } else {
                            $praSum += $grade * $colDef['weight'];
                            $praWeightSum += $colDef['weight'];
                        }
                    }
                }

                $theoAvg = $theoWeightSum > 0 ? round($theoSum / $theoWeightSum, 2) : null;
                $praAvg = $praWeightSum > 0 ? round($praSum / $praWeightSum, 2) : null;

                if ($theoAvg !== null && $praAvg !== null) {
                    $parcialFinal = round(($theoAvg * $subject->theory_weight) + ($praAvg * $subject->practice_weight), 2);
                } elseif ($theoAvg !== null) {
                    $parcialFinal = $theoAvg;
                } else {
                    $parcialFinal = $praAvg;
                }

                // Teórica
                $letter = Coordinate::stringFromColumnIndex($col);
                $sheet->setCellValue("{$letter}{$row}", $theoAvg);
                $this->applyDataCell("{$letter}{$row}");

                // Práctica
                $letter = Coordinate::stringFromColumnIndex($col + 1);
                $sheet->setCellValue("{$letter}{$row}", $praAvg);
                $this->applyDataCell("{$letter}{$row}");

                // Final parcial
                $letter = Coordinate::stringFromColumnIndex($col + 2);
                $sheet->setCellValue("{$letter}{$row}", $parcialFinal);
                $this->applyDataCell("{$letter}{$row}");

                if ($parcialFinal !== null) {
                    $parcialFinals[] = $parcialFinal;
                }

                $col += 3;
            }

            // Promedio final
            $promedioFinal = count($parcialFinals) > 0
                ? round(array_sum($parcialFinals) / count($parcialFinals), 2)
                : null;

            $recoveryGrade = $qual?->recovery_grade;

            $observation = 'Abandono';
            $effectiveGrade = $promedioFinal;
            if ($recoveryGrade !== null && $promedioFinal !== null && $promedioFinal < self::NOTA_MINIMA) {
                $effectiveGrade = $recoveryGrade;
            }
            if ($effectiveGrade !== null) {
                $observation = $effectiveGrade >= self::NOTA_MINIMA ? 'Aprobado' : 'Reprobado';
            }

            // Promedio
            $promLetter = Coordinate::stringFromColumnIndex(self::COL_PROMEDIO);
            $sheet->setCellValue("{$promLetter}{$row}", $promedioFinal);
            $this->applyDataCell("{$promLetter}{$row}", true);

            // Recuperación
            $recLetter = Coordinate::stringFromColumnIndex(self::COL_RECUPERACION);
            $sheet->setCellValue("{$recLetter}{$row}", $recoveryGrade);
            $this->applyDataCell("{$recLetter}{$row}");

            // Observación
            $obsLetter = Coordinate::stringFromColumnIndex(self::COL_OBSERVACION);
            $sheet->setCellValue("{$obsLetter}{$row}", $observation);
            $sheet->getStyle("{$obsLetter}{$row}")->getFont()->setName('Agency FB')->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_BLACK));
            $sheet->getStyle("{$obsLetter}{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            $row++;
            $index++;
        }
    }

    private function applyDataCell(string $cell, bool $bold = false): void
    {
        $sheet = $this->sheet;
        $sheet->getStyle($cell)->getFont()->setName('Arial')->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_BLACK))->setBold($bold);
        $sheet->getStyle($cell)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
    }

    private function fillSummary(): void
    {
        $sheet = $this->sheet;
        $row = self::DATA_START_ROW + count($this->students) + 1;
        $obsCol = Coordinate::stringFromColumnIndex(self::COL_OBSERVACION);
        $promCol = Coordinate::stringFromColumnIndex(self::COL_PROMEDIO);
        $dataEnd = self::DATA_START_ROW + count($this->students);

        $sheet->setCellValue("C{$row}", 'RESUMEN');
        $sheet->getStyle("C{$row}")->getFont()->setBold(true)->setName('Arial')->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_BLACK));

        $totalStudents = count($this->students);
        $aprobados = 0;
        $reprobados = 0;
        $sumPromedios = 0;
        $countPromedios = 0;

        for ($r = self::DATA_START_ROW; $r < $dataEnd; $r++) {
            $obs = $sheet->getCell("{$obsCol}{$r}")->getValue();
            $prom = $sheet->getCell("{$promCol}{$r}")->getValue();

            if ($obs === 'Aprobado') $aprobados++;
            if ($obs === 'Reprobado') $reprobados++;
            if ($prom !== null && $prom !== '') {
                $sumPromedios += (float) $prom;
                $countPromedios++;
            }
        }

        $summaryItems = [
            ['Total Estudiantes:', $totalStudents],
            ['Aprobados:', $aprobados],
            ['Reprobados:', $reprobados],
            ['Promedio General:', $countPromedios > 0 ? round($sumPromedios / $countPromedios, 2) : '—'],
        ];

        foreach ($summaryItems as $i => [$label, $value]) {
            $r = $row + 1 + $i;
            $sheet->setCellValue("C{$r}", $label);
            $sheet->getStyle("C{$r}")->getFont()->setBold(true)->setName('Arial')->setSize(10);
            $sheet->setCellValue("D{$r}", $value);
            $sheet->getStyle("D{$r}")->getFont()->setName('Arial')->setSize(10);
        }
    }

    private function insertLogo(): void
    {
        $sheet = $this->sheet;
        $logoPath = public_path('images/logo.png');
        if (file_exists($logoPath)) {
            $drawing = new Drawing();
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
    }

    private function docenteName(): string
    {
        $docente = \App\Models\Docente::whereHas('subjects', function ($q) {
            $q->where('subjects.id', $this->subject->id)
              ->where('docente_subject.parallel_id', $this->parallel->id);
        })->with('user')->first();

        if (!$docente || !$docente->user) {
            return '';
        }

        $u = $docente->user;
        $degree = $docente->degree?->abbreviation ?? '';
        $name = trim(($u->first_lastname ?? '') . ' ' . ($u->second_lastname ?? '') . ' ' . ($u->name ?? ''));

        return $degree ? "T.S. {$name}" : $name;
    }

    private function year(): string
    {
        return (string) ($this->parallel->year ?? date('Y'));
    }
}
