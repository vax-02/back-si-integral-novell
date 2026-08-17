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
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ParcialReportExportService
{
    private const NOTA_MINIMA = 61;

    private Subject $subject;
    private Parallel $parallel;
    private array $columnsByParcial;
    private array $students;
    private $qualifications;

    public function generate(int $subjectId, int $parallelId): string
    {
        $this->subject = Subject::findOrFail($subjectId);
        $this->parallel = Parallel::with('course.career')->findOrFail($parallelId);
        $this->loadData();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Informe Parciales');

        $this->fillReport($sheet);

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

    private function fillReport($sheet): void
    {
        $subject = $this->subject;
        $parallel = $this->parallel;
        $career = $parallel->course?->career;
        $parciales = $this->columnsByParcial;
        $numParciales = $subject->num_parciales;

        $sheet->setCellValue('A1', 'INFORME DE CALIFICACIONES POR PARCIAL');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->mergeCells('A1:L1');

        $sheet->setCellValue('A3', 'Materia:');
        $sheet->setCellValue('B3', $subject->name . ' (' . $subject->sigla . ')');
        $sheet->setCellValue('A4', 'Paralelo:');
        $sheet->setCellValue('B4', $parallel->paralelo . ' - ' . $parallel->turno);
        $sheet->setCellValue('A5', 'Carrera:');
        $sheet->setCellValue('B5', $career?->name ?? '');
        $sheet->setCellValue('D3', 'Configuración:');
        $sheet->setCellValue('E3', 'Teoría ' . ($subject->theory_weight * 100) . '% / Práctica ' . ($subject->practice_weight * 100) . '%');
        $sheet->setCellValue('D4', 'N° Parciales:');
        $sheet->setCellValue('E4', $numParciales);

        $firstRow = 7;

        $headerRow1 = $firstRow;
        $headerRow2 = $firstRow + 1;

        $sheet->setCellValue('A' . $headerRow1, 'N°');
        $sheet->mergeCells('A' . $headerRow1 . ':A' . $headerRow2);
        $sheet->setCellValue('B' . $headerRow1, 'Estudiante');
        $sheet->mergeCells('B' . $headerRow1 . ':B' . $headerRow2);

        $col = 3;
        for ($p = 1; $p <= $numParciales; $p++) {
            $startCol = Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue($startCol . $headerRow1, $p . '° Parcial');
            $sheet->mergeCells($startCol . $headerRow1 . ':' . Coordinate::stringFromColumnIndex($col + 2) . $headerRow1);

            $sheet->setCellValue($startCol . $headerRow2, 'Prom. Teórica');
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col + 1) . $headerRow2, 'Prom. Práctica');
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col + 2) . $headerRow2, 'Final Parcial');
            $col += 3;
        }

        $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . $headerRow1, 'Prom. Final');
        $sheet->mergeCells(Coordinate::stringFromColumnIndex($col) . $headerRow1 . ':' . Coordinate::stringFromColumnIndex($col) . $headerRow2);
        $col++;

        $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . $headerRow1, 'Recuperación');
        $sheet->mergeCells(Coordinate::stringFromColumnIndex($col) . $headerRow1 . ':' . Coordinate::stringFromColumnIndex($col) . $headerRow2);
        $col++;

        $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . $headerRow1, 'Observación');
        $sheet->mergeCells(Coordinate::stringFromColumnIndex($col) . $headerRow1 . ':' . Coordinate::stringFromColumnIndex($col) . $headerRow2);

        $styleHeader = [
            'font' => ['bold' => true, 'size' => 10],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'D1D5DB']],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
            'borders' => [
                'allBorders' => ['borderStyle' => 'thin'],
            ],
        ];
        for ($c = 1; $c < $col; $c++) {
            $letter = Coordinate::stringFromColumnIndex($c);
            $sheet->getStyle($letter . $headerRow1)->applyFromArray($styleHeader);
            $sheet->getStyle($letter . $headerRow2)->applyFromArray($styleHeader);
        }

        $dataStartRow = $firstRow + 2;
        $row = $dataStartRow;
        $index = 1;

        foreach ($this->students as $student) {
            $qual = $this->qualifications->get($student->id);
            $sheet->setCellValue('A' . $row, $index);
            $sheet->setCellValue('B' . $row, trim(($student->user->first_lastname ?? '') . ' ' . ($student->user->second_lastname ?? '') . ' ' . ($student->user->name ?? '')));

            $col = 3;
            $parcialFinals = [];

            for ($p = 1; $p <= $numParciales; $p++) {
                $parcialCols = $parciales[$p] ?? [];
                $theoAvg = null;
                $praAvg = null;
                $parcialFinal = null;

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
                } elseif ($praAvg !== null) {
                    $parcialFinal = $praAvg;
                }

                $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . $row, $theoAvg);
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($col + 1) . $row, $praAvg);
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($col + 2) . $row, $parcialFinal);

                if ($parcialFinal !== null) {
                    $parcialFinals[] = $parcialFinal;
                }

                $col += 3;
            }

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

            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . $row, $promedioFinal);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col + 1) . $row, $recoveryGrade);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col + 2) . $row, $observation);

            for ($c = 1; $c < $col + 3; $c++) {
                $letter = Coordinate::stringFromColumnIndex($c);
                $sheet->getStyle($letter . $row)->applyFromArray([
                    'alignment' => ['horizontal' => 'center'],
                    'borders' => ['allBorders' => ['borderStyle' => 'thin']],
                ]);
            }

            $row++;
            $index++;
        }

        $summaryRow = $row + 1;
        $sheet->setCellValue('A' . $summaryRow, 'RESUMEN');
        $sheet->getStyle('A' . $summaryRow)->getFont()->setBold(true);

        $totalStudents = count($this->students);
        $aprobados = 0;
        $reprobados = 0;
        $sumPromedios = 0;
        $countPromedios = 0;

        for ($r = $dataStartRow; $r < $row; $r++) {
            $obs = $sheet->getCell(Coordinate::stringFromColumnIndex($col + 2) . $r)->getValue();
            $prom = $sheet->getCell(Coordinate::stringFromColumnIndex($col) . $r)->getValue();

            if ($obs === 'Aprobado') $aprobados++;
            if ($obs === 'Reprobado') $reprobados++;
            if ($prom !== null && $prom !== '') {
                $sumPromedios += (float) $prom;
                $countPromedios++;
            }
        }

        $sheet->setCellValue('A' . $summaryRow + 1, 'Total Estudiantes:');
        $sheet->setCellValue('B' . $summaryRow + 1, $totalStudents);
        $sheet->setCellValue('A' . $summaryRow + 2, 'Aprobados:');
        $sheet->setCellValue('B' . $summaryRow + 2, $aprobados);
        $sheet->setCellValue('A' . $summaryRow + 3, 'Reprobados:');
        $sheet->setCellValue('B' . $summaryRow + 3, $reprobados);
        $sheet->setCellValue('A' . $summaryRow + 4, 'Promedio General:');
        $sheet->setCellValue('B' . $summaryRow + 4, $countPromedios > 0 ? round($sumPromedios / $countPromedios, 2) : '—');

        $sheet->getColumnDimension('A')->setWidth(5);
        $sheet->getColumnDimension('B')->setWidth(35);
        for ($c = 3; $c < $col + 3; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(14);
        }
    }
}
