<?php
// admin/generate_template.php - Generate Word document templates
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_username'])) {
    header("Location: ../login.php");
    exit();
}

// Include PHPWord library (make sure it's installed)
require_once '../includes/PhpWord/vendor/autoload.php';

$type = $_GET['type'] ?? 'objective';

// Create new PHPWord object
$phpWord = new \PhpOffice\PhpWord\PhpWord();

// Add a section
$section = $phpWord->addSection();

// Add title
$section->addText(
    strtoupper($type) . ' QUESTIONS TEMPLATE',
    ['bold' => true, 'size' => 16],
    ['alignment' => 'center']
);

$section->addTextBreak(2);

if ($type === 'objective') {
    // Objective template
    $section->addText(
        'INSTRUCTIONS:',
        ['bold' => true, 'size' => 12]
    );

    $section->addText(
        '1. Fill in your questions using any of the formats below.',
        ['size' => 11]
    );
    $section->addText(
        '2. Mathematical equations: Use \(latex\) format, e.g., \(x^2 + 2x + 1 = 0\)',
        ['size' => 11]
    );
    $section->addText(
        '3. Save this document and upload it in the system.',
        ['size' => 11]
    );

    $section->addTextBreak(2);

    // Format 1: Table format (Recommended)
    $section->addText(
        'FORMAT 1: TABLE FORMAT (Recommended)',
        ['bold' => true, 'size' => 12]
    );

    $section->addText(
        'Create a table in Word with these 8 columns:',
        ['size' => 11]
    );

    // Create a table
    $table = $section->addTable(['borderSize' => 6, 'borderColor' => '000000']);

    // Add header row
    $table->addRow();
    $table->addCell(2000)->addText('Question', ['bold' => true]);
    $table->addCell(1000)->addText('Option A', ['bold' => true]);
    $table->addCell(1000)->addText('Option B', ['bold' => true]);
    $table->addCell(1000)->addText('Option C', ['bold' => true]);
    $table->addCell(1000)->addText('Option D', ['bold' => true]);
    $table->addCell(1000)->addText('Correct', ['bold' => true]);
    $table->addCell(1000)->addText('Difficulty', ['bold' => true]);
    $table->addCell(1000)->addText('Marks', ['bold' => true]);

    // Add sample rows
    $table->addRow();
    $table->addCell(2000)->addText('What is 2+2?');
    $table->addCell(1000)->addText('3');
    $table->addCell(1000)->addText('4');
    $table->addCell(1000)->addText('5');
    $table->addCell(1000)->addText('6');
    $table->addCell(1000)->addText('B');
    $table->addCell(1000)->addText('easy');
    $table->addCell(1000)->addText('1');

    $table->addRow();
    $table->addCell(2000)->addText('Solve: \(x^2 = 9\)');
    $table->addCell(1000)->addText('2');
    $table->addCell(1000)->addText('3');
    $table->addCell(1000)->addText('4');
    $table->addCell(1000)->addText('5');
    $table->addCell(1000)->addText('B');
    $table->addCell(1000)->addText('medium');
    $table->addCell(1000)->addText('2');

    $table->addRow();
    $table->addCell(2000)->addText('What is \(\sqrt{25}\)?');
    $table->addCell(1000)->addText('4');
    $table->addCell(1000)->addText('5');
    $table->addCell(1000)->addText('6');
    $table->addCell(1000)->addText('7');
    $table->addCell(1000)->addText('B');
    $table->addCell(1000)->addText('easy');
    $table->addCell(1000)->addText('1');

    $section->addTextBreak(2);

    // Format 2: Simple format
    $section->addText(
        'FORMAT 2: SIMPLE FORMAT',
        ['bold' => true, 'size' => 12]
    );

    $section->addText('1. What is 2+2?', ['bold' => true]);
    $section->addText('A) 3');
    $section->addText('B) 4');
    $section->addText('C) 5');
    $section->addText('D) 6');
    $section->addText('Correct: B');
    $section->addText('Difficulty: easy');
    $section->addText('Marks: 1');

    $section->addTextBreak(1);

    $section->addText('2. What is the capital of France?', ['bold' => true]);
    $section->addText('A) London');
    $section->addText('B) Berlin');
    $section->addText('C) Paris');
    $section->addText('D) Madrid');
    $section->addText('Correct: C');
    $section->addText('Difficulty: medium');
    $section->addText('Marks: 1');
} elseif ($type === 'subjective') {
    // Subjective template
    $section->addText(
        'INSTRUCTIONS:',
        ['bold' => true, 'size' => 12]
    );

    $section->addText(
        '1. Fill in your questions and answers.',
        ['size' => 11]
    );
    $section->addText(
        '2. Save this document and upload it in the system.',
        ['size' => 11]
    );

    $section->addTextBreak(2);

    // Table format
    $section->addText(
        'FORMAT 1: TABLE FORMAT (Recommended)',
        ['bold' => true, 'size' => 12]
    );

    $table = $section->addTable(['borderSize' => 6, 'borderColor' => '000000']);

    // Add header row
    $table->addRow();
    $table->addCell(3000)->addText('Question', ['bold' => true]);
    $table->addCell(3000)->addText('Answer', ['bold' => true]);
    $table->addCell(1000)->addText('Difficulty', ['bold' => true]);
    $table->addCell(1000)->addText('Marks', ['bold' => true]);

    // Add sample rows
    $table->addRow();
    $table->addCell(3000)->addText('Explain the water cycle.');
    $table->addCell(3000)->addText('The water cycle describes the continuous movement of water on, above, and below the surface of the Earth.');
    $table->addCell(1000)->addText('medium');
    $table->addCell(1000)->addText('5');

    $table->addRow();
    $table->addCell(3000)->addText('What are the three states of matter?');
    $table->addCell(3000)->addText('Solid, Liquid, and Gas');
    $table->addCell(1000)->addText('easy');
    $table->addCell(1000)->addText('3');
} elseif ($type === 'theory') {
    // Theory template
    $section->addText(
        'INSTRUCTIONS:',
        ['bold' => true, 'size' => 12]
    );

    $section->addText(
        '1. Fill in your theory questions.',
        ['size' => 11]
    );
    $section->addText(
        '2. Save this document and upload it in the system.',
        ['size' => 11]
    );

    $section->addTextBreak(2);

    // Table format
    $section->addText(
        'FORMAT 1: TABLE FORMAT (Recommended)',
        ['bold' => true, 'size' => 12]
    );

    $table = $section->addTable(['borderSize' => 6, 'borderColor' => '000000']);

    // Add header row
    $table->addRow();
    $table->addCell(4000)->addText('Question', ['bold' => true]);
    $table->addCell(1000)->addText('Marks', ['bold' => true]);

    // Add sample rows
    $table->addRow();
    $table->addCell(4000)->addText('Discuss the causes and effects of climate change in detail.');
    $table->addCell(1000)->addText('10');

    $table->addRow();
    $table->addCell(4000)->addText('Explain Newton\'s three laws of motion with examples.');
    $table->addCell(1000)->addText('8');

    $table->addRow();
    $table->addCell(4000)->addText('Prove that \(a^2 + b^2 = c^2\) for a right-angled triangle.');
    $table->addCell(1000)->addText('5');
}

$section->addTextBreak(2);
$section->addText(
    'NOTE:',
    ['bold' => true, 'color' => 'FF0000']
);
$section->addText(
    '- You can add as many rows as needed.',
    ['size' => 11]
);
$section->addText(
    '- For mathematical equations, use LaTeX format: \(equation\)',
    ['size' => 11]
);
$section->addText(
    '- Difficulty levels: easy, medium, hard (optional, defaults to medium)',
    ['size' => 11]
);
$section->addText(
    '- Marks are optional (default: 1 for objective/subjective, 5 for theory)',
    ['size' => 11]
);

// Save file
$filename = $type . '_questions_template.docx';

header("Content-Description: File Transfer");
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Transfer-Encoding: binary');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Expires: 0');

$objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
$objWriter->save("php://output");
exit;
