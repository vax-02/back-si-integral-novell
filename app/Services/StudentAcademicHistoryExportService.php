<?php

namespace App\Services;

use App\Models\Qualification;
use App\Models\Student;
use App\Models\StudentCareer;
use App\Models\StudentSubject;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class StudentAcademicHistoryExportService
{
    private const NOTA_MINIMA = 61;

    private const COLOR_DARK = '333333';
    private const COLOR_WHITE = 'FFFFFF';
    private const COLOR_BLACK = '000000';
    private const COLOR_ALT_ROW = 'F8F9FA';
    private const COLOR_HEADER_BG = 'D9E2F3';

    private const COL_NR = 2;         // B
    private const COL_GESTION = 3;    // C
    private const COL_SEMESTRE = 4;   // D
    private const COL_CODIGO = 5;     // E
    private const COL_ASIGNATURA = 6; // F
    private const COL_PREREQ = 7;     // G
    private const COL_NOTA = 8;       // H
    private const COL_RECUP = 9;      // I
    private const COL_OBS = 10;       // J

    private const DATA_START_ROW = 11;
    private const CARGA_HORARIA = 600;

    private Student $student;
    private ?int $careerId;
    private Worksheet $sheet;

    public function generate(int $studentId, ?int $careerId = null): string
    {
        $this->student = Student::with([
            'user:id,name,first_lastname,second_lastname,ci',
            'studentCareers.career:id,name,type,duration',
        ])->findOrFail($studentId);
        $this->careerId = $careerId;

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $careers = $this->getCareers();

        foreach ($careers as $studentCareer) {
            $this->sheet = $spreadsheet->createSheet();
            $sheetName = $studentCareer->career->name;
            if (strlen($sheetName) > 31) {
                $sheetName = substr($sheetName, 0, 31);
            }
            $this->sheet->setTitle($sheetName);

            $this->fillSheet($studentCareer);
        }

        $fileName = 'Historial_Academico_' . $this->getFileName() . '.xlsx';
        $filePath = storage_path('app/' . $fileName);
        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);

        return $filePath;
    }

    private function getCareers()
    {
        $query = $this->student->studentCareers()->with('career:id,name,type,duration');

        if ($this->careerId) {
            $query->where('career_id', $this->careerId);
        }

        return $query->get();
    }

    private function getFileName(): string
    {
        $user = $this->student->user;
        $parts = array_filter([
            $user->first_lastname,
            $user->second_lastname,
            $user->name,
        ]);
        return implode('_', array_map('strtoupper', $parts));
    }

    private function fillSheet(StudentCareer $studentCareer): void
    {
        $this->setupColumns();
        $this->fillHeader($studentCareer);
        $this->fillTableHeaders();
        $this->fillData($studentCareer);
        $this->fillFooter($studentCareer);
    }

    private function setupColumns(): void
    {
        $this->sheet->getDefaultColumnDimension()->setWidth(12);
        $this->sheet->getColumnDimension('A')->setWidth(2);
        $this->sheet->getColumnDimension('B')->setWidth(5);   // N°
        $this->sheet->getColumnDimension('C')->setWidth(18);  // Gestión
        $this->sheet->getColumnDimension('D')->setWidth(22);  // Semestre
        $this->sheet->getColumnDimension('E')->setWidth(10);  // Código
        $this->sheet->getColumnDimension('F')->setWidth(45);  // Asignatura
        $this->sheet->getColumnDimension('G')->setWidth(10);  // Pre Requisito
        $this->sheet->getColumnDimension('H')->setWidth(8);   // Nota
        $this->sheet->getColumnDimension('I')->setWidth(8);  // Prueba Recup
        $this->sheet->getColumnDimension('J')->setWidth(14);  // Observaciones
    }

    private function fillHeader(StudentCareer $studentCareer): void
    {
        $sheet = $this->sheet;
        $user = $this->student->user;
        $career = $studentCareer->career;

        $sheet->mergeCells('B1:K1');
        $sheet->setCellValue('B1', 'HISTORIAL ACADÉMICO');
        $sheet->getStyle('B1')->getFont()->setBold(true)->setName('Calibri')->setSize(26)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('403152'));

        $sheet->getStyle('B1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(30);

        $labels = [
            3 => 'INSTITUCIÓN:',
            4 => 'CARRERA:',
            5 => 'MENCIÓN:',
            6 => 'NIVEL DE FORMACIÓN:',
            7 => 'REGIMEN:',
            8 => 'ESTUDIANTE:',
        ];

        $values = [
            3 => 'INSTITUTO TECNOLÓGICO DE EDUCACIÓN SUPERIOR "NOVELL"',
            4 => mb_strtoupper($career->name),
            5 => '',
            6 => 'TÉCNICO SUPERIOR',
            7 => $career->type == 1 ? 'ANUAL' : 'SEMESTRAL',
            8 => mb_strtoupper(trim("{$user->first_lastname} {$user->second_lastname} {$user->name}")),
        ];



        foreach (range('B', 'C') as $column) {
            $sheet->getColumnDimension($column)->setWidth(9);
        }
        foreach ($labels as $r => $label) {
            $sheet->mergeCells("B{$r}:C{$r}");
            $sheet->setCellValue("B{$r}", $label);
            $sheet->getStyle("B{$r}")->getFont()->setBold(true)->setName('Calibri')->setSize(9)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('403152'));
        }


        $style = [
            'font' => [
                'bold' => true,
                'name' => 'Calibri',
                'size' => 8,
                'color' => ['rgb' => '403152'],
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            ],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => [
                    'rgb' => 'CCC1DA',
                ],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['rgb' => 'FFFFFF'],
                ],
            ],
        ];

        $rows = [
            3 => ['range' => 'D3:K3', 'size' => 9, 'fill' => false],
            4 => ['range' => 'D4:G4'],
            5 => ['range' => 'D5:G5'],
            6 => ['range' => 'D6:G6'],
            7 => ['range' => 'D7:G7'],
            8 => ['range' => 'D8:G8'],
        ];

        foreach (range('D', 'K') as $column) {
            $sheet->getColumnDimension($column)->setWidth(12);
        }

        foreach ($rows as $row => $config) {
            $range = $config['range'];
            $cell = explode(':', $range)[0];

            $sheet->mergeCells($range);
            $sheet->setCellValue($cell, $values[$row]);

            $rowStyle = $style;

            // Cambiar tamaño de letra
            if (isset($config['size'])) {
                $rowStyle['font']['size'] = $config['size'];
            }

            // Quitar fondo si está indicado
            if (isset($config['fill']) && $config['fill'] === false) {
                unset($rowStyle['fill']);
            }

            // No aplicar estilo si está desactivado
            if ($config['style'] ?? true) {
                $sheet->getStyle($range)->applyFromArray($rowStyle);
            }
        }

        // Right side labels
        $rightLabels = [
            4 => 'FECHA DE ADMISIÓN:',
            5 => 'FECHA DE CONCLUSIÓN:',
            6 => 'MATRÍCULA:',
            8 => 'CEDULA DE IDENTIDAD:',
        ];

        $rightValues = [
            4 => $studentCareer->enrolled ? \Carbon\Carbon::parse($studentCareer->enrolled)->format('d/m/Y') : '',
            5 => $studentCareer->status === 'Egresado' ? '—' : '',
            6 => $studentCareer->matricula ?? '',
            8 => $user->ci,
        ];


        foreach (range('H', 'J') as $column) {
            $sheet->getColumnDimension($column)->setWidth(6.5);
        }
        foreach ($rightLabels as $r => $label) {
            $sheet->mergeCells("H{$r}:J{$r}");
            $sheet->setCellValue("H{$r}", $label);
            $sheet->getStyle("H{$r}")->getFont()->setBold(true)->setName('Calibri')->setSize(9)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('403152'));

            $sheet->setCellValue("K{$r}", $rightValues[$r]);
            $sheet->getStyle("K{$r}")->getFont()->setBold(true)->setName('Calibri')->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('403152'));
            $sheet->getStyle("K{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT)
                ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            $sheet->getStyle("K{$r}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('CCC1DA');
            $sheet->getStyle("K{$r}")->getBorders()->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
                ->getColor()->setRGB('FFFFFF');
        }
    }

    private function fillTableHeaders(): void
    {
        $sheet = $this->sheet;
        $sheet->getRowDimension(9)->setRowHeight(30);

        $headers = [
            self::COL_NR         => 'N°',
            self::COL_GESTION    => "GESTIÓN\nACADÉMICA",
            self::COL_SEMESTRE   => 'SEMESTRE/AÑO',
            self::COL_CODIGO     => 'CÓDIGO',
            self::COL_ASIGNATURA => 'ASIGNATURA',
            self::COL_PREREQ     => "PRE\nREQUISITO",
            self::COL_NOTA       => 'NOTA',
            self::COL_RECUP      => "PRUEBA\nRECUP.",
        ];

        $columnWidths = [
            self::COL_NR         => 3,
            self::COL_GESTION    => 12,
            self::COL_SEMESTRE   => 12,
            self::COL_CODIGO     => 8,
            self::COL_ASIGNATURA => 25,
            self::COL_PREREQ     => 8,
            self::COL_NOTA       => 8,
            self::COL_RECUP      => 8,
        ];
        foreach ($headers as $col => $label) {
            $colLetter = Coordinate::stringFromColumnIndex($col);

            $sheet->getColumnDimension($colLetter)
                ->setWidth($columnWidths[$col]);

            $sheet->getStyle("{$colLetter}9")->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
                ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)
                ->setWrapText(true);

            $sheet->mergeCells("{$colLetter}9:{$colLetter}10");
            $sheet->setCellValue("{$colLetter}9", $label);
            $sheet->getStyle("{$colLetter}9")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB('604A7B');

            $sheet->getStyle("{$colLetter}9")->getBorders()->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
                ->getColor()->setRGB('FFFFFF');

            $sheet->getStyle("{$colLetter}9")->getFont()
                ->setBold(true)
                ->setName('Arial Narrow')
                ->setSize(7)
                ->setColor(
                    new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_WHITE)
                );

            $sheet->getStyle("{$colLetter}9")->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
                ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        }

        $sheet->mergeCells("J9:K10");
        $sheet->setCellValue("J9", 'OBSERVACIONES');
        $sheet->getStyle("J9")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('604A7B');
        $sheet->getStyle("J9")->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("J9")->getFont()->setBold(true)->setName('Calibri')->setSize(9)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_WHITE));
        $sheet->getStyle("J9")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

    }

    private function fillData(StudentCareer $studentCareer): void
    {
        $sheet = $this->sheet;

        $qualifications = Qualification::where('student_id', $this->student->id)
            ->where('published', true)
            ->with([
                'subject:id,name,sigla,level,subject_id,career_id',
                'course:id,name,level',
                'parallel:id,paralelo,turno',
                'subject.prerequisite:id,sigla',
            ])
            ->get()
            ->filter(fn($q) => $q->subject && $q->subject->career_id == $studentCareer->career_id)
            ->sortBy(function ($q) {
                return sprintf('%04d-%s', (int) ($q->subject->level ?? 0), strtolower($q->subject->name));
            })
            ->values();

        $gestion = date('Y');
        $row = self::DATA_START_ROW;
        $index = 1;

        foreach ($qualifications as $q) {
            $subject = $q->subject;
            $course = $q->course;
            $parallel = $q->parallel;

            $sheet->setCellValue("B{$row}", $index);
            $sheet->getStyle("B{$row}")->getFont()->setName('Calibri')->setSize(9);
            $sheet->getStyle("B{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            $gestionText = $gestion;
            $sheet->setCellValue("C{$row}", $gestionText);
            $sheet->getStyle("C{$row}")->getFont()->setName('Arial')->setSize(10);
            $sheet->getStyle("C{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            $semestreText = mb_strtoupper(($course->name ?? '') . ' - ' . ($parallel->paralelo ?? ''));
            $sheet->setCellValue("D{$row}", $semestreText);
            $sheet->getStyle("D{$row}")->getFont()->setName('Arial')->setSize(10);
            $sheet->getStyle("D{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            $sheet->setCellValue("E{$row}", $subject->sigla ?? '');
            $sheet->getStyle("E{$row}")->getFont()->setName('Arial')->setSize(10);
            $sheet->getStyle("E{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            $sheet->setCellValue("F{$row}", mb_strtoupper($subject->name ?? ''));
            $sheet->getStyle("F{$row}")->getFont()->setName('Arial')->setSize(10);
            $sheet->getStyle("F{$row}")->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            $prereqSigla = $subject->prerequisite?->sigla ?? '-';
            $sheet->setCellValue("G{$row}", $prereqSigla);
            $sheet->getStyle("G{$row}")->getFont()->setName('Arial')->setSize(10);
            $sheet->getStyle("G{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            $finalGrade = $q->final_grade;
            $sheet->setCellValue("H{$row}", $finalGrade ?? '');
            $sheet->getStyle("H{$row}")->getFont()->setName('Arial')->setSize(10)->setBold((float) ($finalGrade ?? 0) >= self::NOTA_MINIMA);
            $sheet->getStyle("H{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            $recovery = $q->recovery_grade;
            $sheet->setCellValue("I{$row}", $recovery ?? '-');
            $sheet->getStyle("I{$row}")->getFont()->setName('Arial')->setSize(10);
            $sheet->getStyle("I{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            $notaFinal = $recovery ?? $finalGrade;
            $obs = $notaFinal !== null
                ? ((float) $notaFinal >= self::NOTA_MINIMA ? 'APROBADO' : 'REPROBADO')
                : '-';

            $sheet->mergeCells("J{$row}:K{$row}");
            $sheet->setCellValue("J{$row}", $obs);
            $sheet->getStyle("J{$row}")->getFont()->setName('Arial')->setSize(10)->setBold(true);
            $sheet->getStyle("J{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            foreach (['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'] as $colLetter) {
                $sheet->getStyle("{$colLetter}{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('CCC1DA');
                $sheet->getStyle("{$colLetter}{$row}")->getBorders()->getAllBorders()
                    ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
                    ->getColor()->setRGB('FFFFFF');
            }

            $row++;
            $index++;
        }

        // Fill remaining empty rows up to 30
        $totalRows = max(20, $index - 1);
        for ($i = $index; $i <= $totalRows; $i++) {
            $sheet->setCellValue("B{$row}", $i);
            $sheet->getStyle("B{$row}")->getFont()->setName('Arial')->setSize(10);
            $sheet->getStyle("B{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            foreach (['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K'] as $colLetter) {
                $sheet->getStyle("{$colLetter}{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('CCC1DA');
                $sheet->getStyle("{$colLetter}{$row}")->getBorders()->getAllBorders()
                    ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
                    ->getColor()->setRGB('FFFFFF');
            }

            $sheet->mergeCells("J{$row}:K{$row}");
            $sheet->setCellValue("J{$row}", ' - ');
            $sheet->getStyle("J{$row}")->getFont()->setName('Arial')->setSize(10)->getColor()->setRGB('999999');
            $sheet->getStyle("J{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            $row++;
        }
    }

    private function fillFooter(StudentCareer $studentCareer): void
    {
        $sheet = $this->sheet;

        $totalSubjects = Qualification::where('student_id', $this->student->id)
            ->where('published', true)
            ->whereHas('subject', fn($q) => $q->where('career_id', $studentCareer->career_id))
            ->count();

        $aprobadas = Qualification::where('student_id', $this->student->id)
            ->where('published', true)
            ->whereHas('subject', fn($q) => $q->where('career_id', $studentCareer->career_id))
            ->where(function ($q) {
                $q->whereNotNull('final_grade')->where('final_grade', '>=', self::NOTA_MINIMA);
            })
            ->count();

        $allGrades = Qualification::where('student_id', $this->student->id)
            ->where('published', true)
            ->whereHas('subject', fn($q) => $q->where('career_id', $studentCareer->career_id))
            ->whereNotNull('final_grade')
            ->pluck('final_grade');

        $promedio = $allGrades->count() > 0
            ? round($allGrades->avg(), 2)
            : '—';

        $today = \Carbon\Carbon::now();
        $fechaFooter = 'Oruro, ' . $today->translatedFormat('d') . ' de ' . $today->translatedFormat('F') . ' de ' . $today->format('Y');

        $row = self::DATA_START_ROW + max(20, $totalSubjects) + 2;

        $sheet->mergeCells("B{$row}:C{$row}");
        $sheet->setCellValue("B{$row}", 'Lugar y fecha:');
        $sheet->getStyle("B{$row}")->getFont()->setName('Calibri')->setSize(11);
        $sheet->getStyle("B{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('CCC1DA');
        $sheet->getStyle("B{$row}")->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->getColor()->setRGB('FFFFFF');

        $sheet->mergeCells("D{$row}:F{$row}");
        $sheet->setCellValue("D{$row}", $fechaFooter);
        $sheet->getStyle("D{$row}")->getFont()->setName('Calibri')->setSize(11);
        $sheet->getStyle("D{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('CCC1DA');
        $sheet->getStyle("D{$row}")->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->getColor()->setRGB('FFFFFF');

        $row += 3;

        $sheet->mergeCells("F{$row}:G{$row}");
        $sheet->setCellValue("F{$row}", 'Firma y Sello');
        $sheet->getStyle("F{$row}")->getFont()->setBold(true)->setName('Calibri')->setSize(10);
        $sheet->getStyle("F{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $row++;
        $sheet->mergeCells("F{$row}:G{$row}");
        $sheet->setCellValue("F{$row}", 'DIRECTOR ACADEMICO');
        $sheet->getStyle("F{$row}")->getFont()->setBold(true)->setName('Calibri')->setSize(10);
        $sheet->getStyle("F{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $row += 2;

        // Escala de valoración
        $sheet->mergeCells("C{$row}:E{$row}");
        $sheet->setCellValue("C{$row}", 'ESCALA DE VALORACIÓN');
        $sheet->getStyle("C{$row}")->getFont()->setBold(true)->setName('Calibri')->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_WHITE));
        $sheet->getStyle("C{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('604A7B');
        $sheet->getStyle("C{$row}")->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->getColor()->setRGB('000000');
        $sheet->getStyle("C{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $sheet->mergeCells("F{$row}:G{$row}");
        $sheet->mergeCells("F{$row}:G" . ($row + 4));

        $sheet->setCellValue("F{$row}", 'Sello de la Institución');
        $sheet->getStyle("F{$row}")->getFont()->setBold(true)->setName('Calibri')->setSize(11);

        $sheet->getStyle("F{$row}:G" . ($row + 4))->getAlignment()
        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
        ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

        $sheet->mergeCells("H{$row}:J{$row}");
        $sheet->setCellValue("H{$row}", 'Carga Horaria');
        $sheet->getStyle("H{$row}")->getFont()->setBold(true)->setName('Calibri')->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_WHITE));
        $sheet->getStyle("H{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('604A7B');
        $sheet->getStyle("H{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle("H{$row}")->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->getColor()->setRGB('000000');

        $sheet->getStyle("K{$row}")->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->getColor()->setRGB('000000');
        $sheet->getStyle("K{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('CCC1DA');


        $row++;
        $sheet->setCellValue("C{$row}", '61 a 100');
        $sheet->getStyle("C{$row}")->getFont()->setName('Calibri')->setSize(9);
        $sheet->getStyle("C{$row}")->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->getColor()->setRGB('000000');
        $sheet->getStyle("C{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("C{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('CCC1DA');

        $sheet->mergeCells("D{$row}:E{$row}");
        $sheet->setCellValue("D{$row}", 'APROBADO');
        $sheet->getStyle("D{$row}")->getFont()->setName('Calibri')->setSize(9)->setBold(true);
        $sheet->getStyle("D{$row}")->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->getColor()->setRGB('000000');
        $sheet->getStyle("D{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("D{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('CCC1DA');

        $sheet->mergeCells("H{$row}:J{$row}");
        $sheet->setCellValue("H{$row}", 'Asignaturas aprobadas:');
        $sheet->getStyle("H{$row}")->getFont()->setBold(true)->setName('Calibri')->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_WHITE));

        $sheet->getStyle("H{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('604A7B');
        $sheet->getStyle("H{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle("H{$row}")->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->getColor()->setRGB('000000');

        $sheet->setCellValue("K{$row}", "{$aprobadas}/{$totalSubjects}.");
        $sheet->getStyle("K{$row}")->getFont()->setName('Calibri')->setSize(9)->setBold(true);
        $sheet->getStyle("K{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("K{$row}")->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->getColor()->setRGB('000000');
        $sheet->getStyle("K{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('CCC1DA');

        $row++;
        $sheet->setCellValue("C{$row}", '1 a 60');
        $sheet->getStyle("C{$row}")->getFont()->setName('Calibri')->setSize(9);
        $sheet->getStyle("C{$row}")->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->getColor()->setRGB('000000');
        $sheet->getStyle("C{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("C{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('CCC1DA');

        $sheet->mergeCells("D{$row}:E{$row}");
        $sheet->setCellValue("D{$row}", 'REPROBADO');
        $sheet->getStyle("D{$row}")->getFont()->setName('Calibri')->setSize(9)->setBold(true);
        $sheet->getStyle("D{$row}")->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->getColor()->setRGB('000000');
        $sheet->getStyle("D{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("D{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('CCC1DA');

        $sheet->mergeCells("H{$row}:J{$row}");
        $sheet->mergeCells("H{$row}:J" . ($row + 1));
        $sheet->setCellValue("H{$row}", 'Promedio de calificaciones:');

        $sheet->getStyle("H{$row}")->getFont()->setBold(true)->setName('Calibri')->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_WHITE));
        $sheet->getStyle("H{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('604A7B');
        $sheet->getStyle("H{$row}")->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT)
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle("H{$row}")->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->getColor()->setRGB('000000');

        $sheet->mergeCells("K{$row}:K" . ($row + 1));
        $sheet->setCellValue("K{$row}", $promedio);
        $sheet->getStyle("K{$row}")->getFont()->setName('Calibri')->setSize(9)->setBold(true);
        $sheet->getStyle("K{$row}")->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle("K{$row}")->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->getColor()->setRGB('000000');
        $sheet->getStyle("K{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('CCC1DA');

        $row ++;
        $sheet->setCellValue("C{$row}", self::NOTA_MINIMA);
        $sheet->getStyle("C{$row}")->getFont()->setName('Calibri')->setSize(9);
        $sheet->getStyle("C{$row}")->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->getColor()->setRGB('000000');
        $sheet->getStyle("C{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("C{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('CCC1DA');

        $sheet->mergeCells("D{$row}:E{$row}");
        $sheet->setCellValue("D{$row}", 'NOTA MÍNIMA');
        $sheet->getStyle("D{$row}")->getFont()->setName('Calibri')->setSize(9)->setBold(true);
        $sheet->getStyle("D{$row}")->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->getColor()->setRGB('000000');
        $sheet->getStyle("D{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("D{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('CCC1DA');

        $row++;
        $sheet->mergeCells("C{$row}:K{$row}");
        $sheet->setCellValue("C{$row}", 'Cualquier raspadura o enmienda invalida el presente documento.');
        $sheet->getStyle("C{$row}")->getFont()->setName('Calibri')->setSize(9)->setItalic(true)->getColor()->setRGB('666666');
        $sheet->getStyle("C{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("C{$row}")->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->getColor()->setRGB('000000');
        $sheet->getStyle("C{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('CCC1DA');
    }
}
