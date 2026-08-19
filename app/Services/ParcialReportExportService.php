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
        $this->sheet->getColumnDimension('C')->setWidth(18);
        $this->sheet->getColumnDimension('D')->setWidth(30);
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
            3 => 'MATERIA:',
            4 => 'DOCENTE:',
            5 => 'CURSO:',
            6 => 'AÑO/SEMESTRE:',
        ];

        $values = [
            2 => mb_strtoupper($career?->name ?? ''),
            3 => $subject->name,
            4 => $this->docenteName(),
            5 => mb_strtoupper($this->parallel->turno ?? '') . ' - ' . $this->parallel->paralelo,
            6 => mb_strtoupper($this->parallel->course?->name ?? '')
        ];

        foreach ($labels as $r => $label) {
            $sheet->setCellValue("C{$r}", $label);
            $sheet->getStyle("C{$r}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_DARK);
            $sheet->getStyle("C{$r}")->getFont()->setBold(true)->setName('Calibri')->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_WHITE));
            $sheet->getStyle("C{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            $sheet->setCellValue("D{$r}", $values[$r]);
            $sheet->getStyle("D{$r}")->getFont()->setName('Calibri')->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_BLACK));
            $sheet->getStyle("D{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        }


        $sheet->getStyle("D2:D6")->getBorders()->getAllBorders()
        ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

        $sheet->getStyle("D2:D6")->getBorders()->getAllBorders()->getColor()->setRGB('000000');

        // SIGLA (row 5)
        $sheet->setCellValue('L6', 'SIGLA:');
        $sheet->getStyle('L6')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_DARK);
        $sheet->getStyle('L6')->getFont()->setBold(true)->setName('Calibri')->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_WHITE));
        $sheet->getStyle('L6')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->setCellValue('M6', $subject->sigla);
        $sheet->getStyle('M6')->getFont()->setName('Calibri')->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_BLACK));
        $sheet->getStyle('M6')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

        // GESTIÓN (row 6)
        $sheet->setCellValue('L7', 'GESTIÓN:');
        $sheet->getStyle('L7')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_DARK);
        $sheet->getStyle('L7')->getFont()->setBold(true)->setName('Calibri')->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_WHITE));
        $sheet->getStyle('L7')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->setCellValue('M7', $this->year());
        $sheet->getStyle('M7')->getFont()->setName('Calibri')->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_BLACK));
        $sheet->getStyle('M7')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

        $sheet->getStyle("M6:M7")->getBorders()->getAllBorders()
        ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

        $sheet->getStyle("M6:M7")->getBorders()->getAllBorders()->getColor()->setRGB('000000');
    }

    private function fillParcialHeaders(): void
    {
        $sheet = $this->sheet;
        $numParciales = $this->subject->num_parciales;


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

    }

    private function fillSubHeaders(): void
    {
        $sheet = $this->sheet;
        $numParciales = $this->subject->num_parciales;

        $sheet->setCellValue('B9', '#');
        $this->applyDarkHeader('B9');
        $sheet->getRowDimension(9)->setRowHeight(35);

        // D9: Apellidos y Nombres
        $sheet->mergeCells('C9:D9');
        $sheet->setCellValue('C9', 'Apellidos y Nombres');
        $this->applyDarkHeader('C9');

        $col = self::COL_FIRST_DATA; // E=5

        for ($p = 1; $p <= $numParciales; $p++) {
            $theoLetter = Coordinate::stringFromColumnIndex($col);
            $praLetter = Coordinate::stringFromColumnIndex($col + 1);
            $finLetter = Coordinate::stringFromColumnIndex($col + 2);

            $sheet->getColumnDimension($theoLetter)->setWidth(7);
            $sheet->getColumnDimension($praLetter)->setWidth(9);
            $sheet->getColumnDimension($finLetter)->setWidth(10);

            $this->applyDarkHeader("{$theoLetter}9");
            $this->applyDarkHeader("{$praLetter}9");
            $this->applyDarkHeader("{$finLetter}9");

            $sheet->setCellValue("{$theoLetter}9", "Prom. Ev\nTeórica");
            $sheet->setCellValue("{$praLetter}9", "Prom. Eval\nPráctica");
            $sheet->setCellValue("{$finLetter}9", "Calificación\nFinal");

            $col += 3;
        }

        // K9: PROMEDIO
        $promLetter = Coordinate::stringFromColumnIndex($col);
        $sheet->setCellValue("{$promLetter}9", "PROMEDIO 1° Y 2°\nPARCIAL");
        $this->applyDarkHeader("{$promLetter}9");
        $col++;

        // L9: Prueba de Recuperación
        $recLetter = Coordinate::stringFromColumnIndex($col);

        $sheet->getColumnDimension($recLetter)->setWidth(9);
        $this->applyDarkHeader("{$recLetter}9");
        $sheet->setCellValue("{$recLetter}9", "Prueba de\nRecuper.");
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
                foreach (['B','C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'] as $colLetter) {
                    $sheet->getStyle("{$colLetter}{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_ALT_ROW);
                }
            }
            // Nr

            $sheet->setCellValue("B{$row}", $index);
            $sheet->getStyle("C{$row}")->getFont()->setName('Arial')->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_BLACK));
            $sheet->getStyle("C{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            // Name
            $sheet->mergeCells("C{$row}:D{$row}");
            $fullName = mb_strtoupper(trim(($student->user->first_lastname ?? '') . ' ' . ($student->user->second_lastname ?? '') . ' ' . ($student->user->name ?? '')));
            $sheet->setCellValue("C{$row}", $fullName);
            $sheet->getStyle("C{$row}")->getFont()->setName('Arial Narrow')->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_BLACK));
            $sheet->getStyle("C{$row}")->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

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
        $lastRow = $row-1;
        $sheet->getStyle("B10:M{$lastRow}")->getBorders()->getAllBorders()
        ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

        $sheet->getStyle("B10:M{$lastRow}")->getBorders()->getAllBorders()->getColor()->setRGB('000000');

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
        $row = self::DATA_START_ROW + count($this->students) + 5;
        $obsCol = Coordinate::stringFromColumnIndex(self::COL_OBSERVACION);
        $promCol = Coordinate::stringFromColumnIndex(self::COL_PROMEDIO);
        $dataEnd = self::DATA_START_ROW + count($this->students);

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


        $summaryStart = $row + 1;
        $summaryEnd = $summaryStart + count($summaryItems) - 1;

        foreach ($summaryItems as $i => [$label, $value]) {
            $r = $summaryStart + $i;

            $sheet->mergeCells("E{$r}:F{$r}");
            $sheet->getStyle("E{$r}:F{$r}")
                ->getFont()
                ->setBold(true)
                ->setName('Arial')
                ->setSize(10);

            $sheet->getStyle("E{$r}:F{$r}")
                ->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT)
                ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            $sheet->setCellValue("E{$r}", $label);
            $sheet->getStyle("E{$r}")
                ->getFont()
                ->setName('Arial')
                ->setSize(10);


            $sheet->setCellValue("G{$r}", $value);
            $sheet->getStyle("G{$r}")
                ->getFont()
                ->setName('Arial')
                ->setSize(10);

            $sheet->getStyle("G{$r}")
                ->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        }

        // Bordes dinámicos del Summary
        $sheet->getStyle("E{$summaryStart}:G{$summaryEnd}")
            ->getBorders()
            ->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

        $sheet->getStyle("E{$summaryStart}:G{$summaryEnd}")
            ->getBorders()
            ->getAllBorders()
            ->getColor()
            ->setRGB('000000');

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
            $drawing->setCoordinates('L1');
            $drawing->setOffsetX(0);
            $drawing->setOffsetY(5);
            $drawing->setWidth(160);
            $drawing->setHeight(60);
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
