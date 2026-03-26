<?php
require_once '../includes/config.php';

// Check a few questions to see how math equations are stored
$stmt = $pdo->query("SELECT id, question_text, option_a, option_b, option_c, option_d FROM objective_questions LIMIT 5");
$questions = $stmt->fetchAll();

echo "<h1>Math Equation Debug</h1>";
echo "<p>Checking how mathematical equations are stored in the database:</p>";

foreach ($questions as $index => $question) {
    echo "<h3>Question " . ($index + 1) . " (ID: " . $question['id'] . ")</h3>";
    echo "<h4>Question Text:</h4>";
    echo "<pre>" . htmlspecialchars($question['question_text']) . "</pre>";
    
    echo "<h4>Options:</h4>";
    echo "<p><strong>A:</strong> " . htmlspecialchars($question['option_a']) . "</p>";
    echo "<p><strong>B:</strong> " . htmlspecialchars($question['option_b']) . "</p>";
    echo "<p><strong>C:</strong> " . htmlspecialchars($question['option_c']) . "</p>";
    echo "<p><strong>D:</strong> " . htmlspecialchars($question['option_d']) . "</p>";
    
    echo "<hr>";
}

// Common LaTeX patterns to check for
echo "<h2>Common LaTeX Patterns to Look For:</h2>";
echo "<ul>";
echo "<li>Inline math: \$x^2 + y^2 = z^2\$</li>";
echo "<li>Display math: \$\$E = mc^2\$\$</li>";
echo "<li>Fractions: \$\$\\frac{a}{b}\$\$</li>";
echo "<li>Square roots: \$\$\\sqrt{x}\$\$</li>";
echo "<li>Greek letters: \$\$\\alpha, \\beta, \\gamma\$\$</li>";
echo "</ul>";

echo "<h2>Test MathJax Rendering:</h2>";
?>
<!DOCTYPE html>
<html>
<head>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/mathjax/3.2.2/es5/tex-mml-chtml.js"></script>
</head>
<body>
    <p>Test equation: \(x = \frac{-b \pm \sqrt{b^2 - 4ac}}{2a}\)</p>
    <p>Display equation: $$\int_{-\infty}^{\infty} e^{-x^2} dx = \sqrt{\pi}$$</p>
</body>
</html>
<?php
