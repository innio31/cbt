<?php
// admin/word_to_csv_converter.php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['word_file'])) {
    $file = $_FILES['word_file'];

    if ($file['error'] === UPLOAD_ERR_OK) {
        // Extract text from Word
        $zip = new ZipArchive;
        if ($zip->open($file['tmp_name']) === TRUE) {
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();

            if ($xml) {
                // Clean the text
                $text = strip_tags($xml);
                $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
                $text = preg_replace('/\s+/', ' ', $text);

                // Convert to CSV format
                $lines = explode('.', $text); // Split by sentences
                $csv = "Question,Option A,Option B,Option C,Option D,Correct Answer,Difficulty,Marks\n";

                foreach ($lines as $index => $line) {
                    $line = trim($line);
                    if (strlen($line) > 10) { // Only use reasonable length lines
                        $csv .= '"' . str_replace('"', '""', $line) . '",Option A,Option B,Option C,Option D,A,medium,1' . "\n";
                    }
                }

                // Offer download
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="converted_questions.csv"');
                echo $csv;
                exit;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Word to CSV Converter</title>
</head>

<body>
    <h1>Convert Word Document to CSV</h1>
    <p>Upload your Word document (.docx) and we'll convert it to CSV format that you can edit and upload.</p>

    <form method="POST" enctype="multipart/form-data">
        <input type="file" name="word_file" accept=".docx" required>
        <button type="submit">Convert to CSV</button>
    </form>

    <h2>Instructions:</h2>
    <ol>
        <li>Upload your Word document with questions</li>
        <li>Download the CSV file</li>
        <li>Open CSV in Excel or Google Sheets</li>
        <li>Fill in the options, correct answers, etc.</li>
        <li>Upload the CSV file in the main import page</li>
    </ol>
</body>

</html>