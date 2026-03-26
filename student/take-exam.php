<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

if (!isStudentLoggedIn()) {
    header("Location: login.php");
    exit();
}

$student_id = $_SESSION['student_id'];
$exam_id = $_GET['exam_id'] ?? 0;

// Get exam details
$stmt = $pdo->prepare("
    SELECT e.*, s.subject_name 
    FROM exams e 
    JOIN subjects s ON e.subject_id = s.id 
    WHERE e.id = ? AND e.is_active = 1
");
$stmt->execute([$exam_id]);
$exam = $stmt->fetch();

if (!$exam) {
    header("Location: index.php");
    exit();
}

// Check if student has already taken this exam
$stmt = $pdo->prepare("SELECT * FROM exam_sessions WHERE student_id = ? AND exam_id = ? AND status = 'completed'");
$stmt->execute([$student_id, $exam_id]);
if ($stmt->fetch()) {
    header("Location: index.php");
    exit();
}

// Check for existing exam session or create new one
$stmt = $pdo->prepare("SELECT * FROM exam_sessions WHERE student_id = ? AND exam_id = ? AND status = 'in_progress'");
$stmt->execute([$student_id, $exam_id]);
$exam_session = $stmt->fetch();

if (!$exam_session) {
    // Create new exam session
    $start_time = date('Y-m-d H:i:s');
    $end_time = date('Y-m-d H:i:s', strtotime("+{$exam['duration_minutes']} minutes"));

    $stmt = $pdo->prepare("INSERT INTO exam_sessions (student_id, exam_id, start_time, end_time, status) VALUES (?, ?, ?, ?, 'in_progress')");
    $stmt->execute([$student_id, $exam_id, $start_time, $end_time]);
    $session_id = $pdo->lastInsertId();
} else {
    $session_id = $exam_session['id'];
    $start_time = $exam_session['start_time'];
    $end_time = $exam_session['end_time'];
}

// Get objective questions for this exam
$topics_json = $exam['topics'];
$topics_array = json_decode($topics_json, true);
$question_limit = (int)$exam['objective_count'];

if (!empty($topics_array) && is_array($topics_array)) {
    $topic_ids = implode(',', array_map('intval', $topics_array));
    $query = "
        SELECT oq.* 
        FROM objective_questions oq 
        WHERE oq.subject_id = {$exam['subject_id']}
        AND oq.topic_id IN ($topic_ids)
        ORDER BY RAND() 
        LIMIT $question_limit
    ";
    $stmt = $pdo->query($query);
} else {
    $query = "
        SELECT oq.* 
        FROM objective_questions oq 
        WHERE oq.subject_id = {$exam['subject_id']}
        ORDER BY RAND() 
        LIMIT $question_limit
    ";
    $stmt = $pdo->query($query);
}

$questions = $stmt->fetchAll();

// Shuffle the questions array to randomize question order
shuffle($questions);

// Shuffle options for each question
foreach ($questions as $index => $question) {
    $options = [
        'A' => $question['option_a'],
        'B' => $question['option_b'],
        'C' => $question['option_c'],
        'D' => $question['option_d']
    ];

    // Shuffle the options while keeping the keys
    $keys = array_keys($options);
    shuffle($keys);

    $shuffled_options = [];
    foreach ($keys as $key) {
        $shuffled_options[$key] = $options[$key];
    }

    $questions[$index]['shuffled_options'] = $shuffled_options;
    $questions[$index]['shuffled_keys'] = array_keys($shuffled_options);
}
// In exam.php, after shuffling the questions and before the HTML starts
// Store the questions in exam_session_questions table
foreach ($questions as $question) {
    try {
        $stmt = $pdo->prepare("INSERT INTO exam_session_questions (session_id, question_id) VALUES (?, ?)");
        $stmt->execute([$session_id, $question['id']]);
    } catch (Exception $e) {
        // Log error but don't break the exam
        error_log("Error storing exam session question: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam - <?php echo htmlspecialchars($exam['exam_name']); ?></title>
    <!-- MathJax for rendering mathematical equations -->
    <script src="https://polyfill.io/v3/polyfill.min.js?features=es6"></script>
    <script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: #f8f9fa;
            padding-top: 120px;
            /* Space for fixed header */
        }

        .fixed-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: white;
            padding: 1rem 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 1000;
        }

        .exam-info h2 {
            color: #333;
            margin-bottom: 0.5rem;
            font-size: 1.5rem;
        }

        .exam-info p {
            color: #666;
            margin-bottom: 0.25rem;
        }

        .timer-container {
            background: #ff6b6b;
            color: white;
            padding: 1rem 2rem;
            border-radius: 10px;
            text-align: center;
            min-width: 150px;
        }

        .timer {
            font-size: 1.5rem;
            font-weight: bold;
        }

        .timer-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 2rem;
        }

        .exam-card {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .question {
            margin-bottom: 2rem;
        }

        .question-number {
            background: #4a90e2;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            font-weight: bold;
            margin-bottom: 1rem;
            display: inline-block;
        }

        .question-text {
            font-size: 1.1rem;
            margin-bottom: 1.5rem;
            line-height: 1.6;
            min-height: 80px;
        }

        .question-text math {
            font-size: 1.2em;
        }

        .options {
            display: grid;
            gap: 0.5rem;
            margin-bottom: 2rem;
        }

        .option {
            display: flex;
            align-items: center;
            padding: 1rem;
            border: 2px solid #ddd;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .option:hover {
            border-color: #4a90e2;
            background: #f8f9fa;
        }

        .option.selected {
            border-color: #4a90e2;
            background: #e7f3ff;
        }

        .option input {
            margin-right: 1rem;
        }

        .navigation {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid #eee;
        }

        .nav-btn {
            background: #4a90e2;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            transition: background 0.3s ease;
        }

        .nav-btn:hover {
            background: #357abd;
        }

        .nav-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }

        .question-counter {
            color: #666;
            font-weight: bold;
        }

        .submit-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .submit-btn:hover {
            background: #218838;
        }

        .theory-section {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 10px;
            padding: 1.5rem;
            margin-top: 2rem;
        }

        .theory-section h3 {
            color: #856404;
            margin-bottom: 1rem;
        }

        .warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .progress-bar {
            width: 100%;
            height: 5px;
            background: #e0e0e0;
            border-radius: 5px;
            margin-bottom: 1rem;
            overflow: hidden;
        }

        .progress {
            height: 100%;
            background: #4a90e2;
            transition: width 0.3s ease;
        }
    </style>
</head>

<body>
    <div class="fixed-header">
        <div class="exam-info">
            <h2><?php echo htmlspecialchars($exam['exam_name']); ?></h2>
            <p><strong>Subject:</strong> <?php echo htmlspecialchars($exam['subject_name']); ?></p>
            <p><strong>Questions:</strong> <span id="currentQuestion">1</span> of <?php echo count($questions); ?></p>
        </div>
        <div class="timer-container">
            <div class="timer" id="timer">00:00:00</div>
            <div class="timer-label">TIME REMAINING</div>
        </div>
    </div>

    <div class="container">
        <form id="examForm">
            <div class="exam-card">
                <?php if (empty($questions)): ?>
                    <div class="warning">
                        <h3>⚠️ No Questions Available</h3>
                        <p>There are no objective questions available for this exam. Please contact your administrator.</p>
                    </div>
                <?php else: ?>
                    <!-- Progress Bar -->
                    <div class="progress-bar">
                        <div class="progress" id="progressBar" style="width: <?php echo (1 / count($questions)) * 100; ?>%"></div>
                    </div>

                    <!-- Questions Container -->
                    <div id="questionsContainer">
                        <?php foreach ($questions as $index => $question): ?>
                            <div class="question-page" id="questionPage<?php echo $index + 1; ?>" style="<?php echo $index === 0 ? '' : 'display: none;'; ?>">
                                <div class="question">
                                    <div class="question-number">Question <?php echo $index + 1; ?></div>
                                    <div class="question-text">
    <?php
    // Process LaTeX delimiters without escaping HTML
    $question_text = $question['question_text'];
    
    // Convert LaTeX delimiters if needed ($$...$$ to \(...\))
    $question_text = preg_replace('/\$\$(.*?)\$\$/s', '\\\\(\\1\\\\)', $question_text);
    $question_text = preg_replace('/\$(.*?)\$/s', '\\(\\1\\)', $question_text);
    
    // Allow specific HTML tags (u, strong, em, br, p, etc.) but prevent XSS
    $allowed_tags = '<u><strong><em><b><i><br><p><span><div><sub><sup>';
    echo strip_tags($question_text, $allowed_tags);
    ?>
</div>
                                    <div class="options">
                                        <?php
                                        $shuffled_keys = $question['shuffled_keys'];
                                        $shuffled_options = $question['shuffled_options'];
                                        ?>
                                        <?php foreach ($shuffled_keys as $key_index => $key): ?>
                                            <label class="option" for="option_<?php echo $question['id']; ?>_<?php echo $key; ?>">
                                                <input type="radio"
                                                    id="option_<?php echo $question['id']; ?>_<?php echo $key; ?>"
                                                    name="question_<?php echo $question['id']; ?>"
                                                    value="<?php echo $key; ?>"
                                                    data-question-index="<?php echo $index; ?>">
                                                <span>
                                                <?php
$option_text = $shuffled_options[$key];
// Convert LaTeX in options
$option_text = preg_replace('/\$\$(.*?)\$\$/s', '\\\\(\\1\\\\)', $option_text);
$option_text = preg_replace('/\$(.*?)\$/s', '\\(\\1\\)', $option_text);
// Allow specific HTML tags
$allowed_tags = '<u><strong><em><b><i><br><p><span><div><sub><sup>';
echo $key . '. ' . strip_tags($option_text, $allowed_tags);
?>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="navigation">
                                    <button type="button" class="nav-btn" onclick="previousQuestion()" id="prevBtn" style="<?php echo $index === 0 ? 'display: none;' : ''; ?>">
                                        ← Previous
                                    </button>

                                    <div class="question-counter">
                                        Question <?php echo $index + 1; ?> of <?php echo count($questions); ?>
                                    </div>

                                    <?php if ($index < count($questions) - 1): ?>
                                        <button type="button" class="nav-btn" onclick="nextQuestion()" id="nextBtn">
                                            Next →
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="submit-btn" onclick="submitExam()" id="submitBtn">
                                            Submit Exam
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($exam['theory_count'] > 0): ?>
                    <div class="theory-section">
                        <h3>📚 Theory Questions</h3>
                        <p>There are <?php echo $exam['theory_count']; ?> theory questions for this exam.
                            Please write your answers on the provided answer sheet. The theory questions will be displayed after you submit the objective section.</p>
                        <p><strong>Note:</strong> Theory answers should be written on paper, not in this system.</p>
                    </div>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <script>
        // Exam configuration
        const totalQuestions = <?php echo count($questions); ?>;
        const examDuration = <?php echo $exam['duration_minutes'] * 60; ?>;
        let currentQuestion = 1;
        let timeRemaining = examDuration;
        let answers = {};

        // Initialize MathJax
        MathJax = {
            tex: {
                inlineMath: [
                    ['\\(', '\\)']
                ],
                displayMath: [
                    ['\\\\[', '\\\\]']
                ]
            },
            svg: {
                fontCache: 'global'
            }
        };

        // Timer functionality
        function updateTimer() {
            const hours = Math.floor(timeRemaining / 3600);
            const minutes = Math.floor((timeRemaining % 3600) / 60);
            const seconds = timeRemaining % 60;

            document.getElementById('timer').textContent =
                `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;

            if (timeRemaining <= 0) {
                autoSubmitExam();
            } else {
                timeRemaining--;
            }
        }

        // Start timer
        const timerInterval = setInterval(updateTimer, 1000);

        // Navigation functions
        function showQuestion(questionNumber) {
            // Hide all questions
            document.querySelectorAll('.question-page').forEach(page => {
                page.style.display = 'none';
            });

            // Show selected question
            document.getElementById('questionPage' + questionNumber).style.display = 'block';

            // Update current question display
            document.getElementById('currentQuestion').textContent = questionNumber;
            currentQuestion = questionNumber;

            // Update progress bar
            const progress = (questionNumber / totalQuestions) * 100;
            document.getElementById('progressBar').style.width = progress + '%';

            // Update navigation buttons
            document.getElementById('prevBtn').style.display = questionNumber === 1 ? 'none' : 'block';

            // Re-render MathJax for the current question
            MathJax.typesetPromise();
        }

        function nextQuestion() {
            if (currentQuestion < totalQuestions) {
                saveCurrentAnswer();
                showQuestion(currentQuestion + 1);
            }
        }

        function previousQuestion() {
            if (currentQuestion > 1) {
                saveCurrentAnswer();
                showQuestion(currentQuestion - 1);
            }
        }

        // Save current answer
        function saveCurrentAnswer() {
            const currentQuestionElement = document.getElementById('questionPage' + currentQuestion);
            const radioButtons = currentQuestionElement.querySelectorAll('input[type="radio"]:checked');

            if (radioButtons.length > 0) {
                const questionId = radioButtons[0].name.replace('question_', '');
                answers[questionId] = radioButtons[0].value;
            }
        }

        // Keyboard navigation
        document.addEventListener('keydown', function(e) {
            if (e.key === 'n' || e.key === 'N') {
                e.preventDefault();
                nextQuestion();
            } else if (e.key === 'p' || e.key === 'P') {
                e.preventDefault();
                previousQuestion();
            }
        });

        // Option selection
        document.addEventListener('click', function(e) {
            if (e.target.type === 'radio') {
                const option = e.target.closest('.option');
                if (option) {
                    // Remove selected class from all options in this question
                    option.parentNode.querySelectorAll('.option').forEach(opt => {
                        opt.classList.remove('selected');
                    });

                    // Add selected class to clicked option
                    option.classList.add('selected');

                    // Auto-save answer
                    saveCurrentAnswer();
                }
            }
        });

        // Auto-submit when time is up
        function autoSubmitExam() {
            clearInterval(timerInterval);
            alert('Time is up! Your exam will be submitted automatically.');
            submitExam();
        }

        // Submit exam function
        function submitExam() {
            if (!confirm('Are you sure you want to submit your exam? You cannot change your answers after submission.')) {
                return;
            }

            // Save any unsaved answer
            saveCurrentAnswer();

            const formData = new FormData();
            const answeredQuestions = Object.keys(answers).length;

            if (answeredQuestions < totalQuestions) {
                if (!confirm(`You have answered ${answeredQuestions} out of ${totalQuestions} questions. Are you sure you want to submit anyway?`)) {
                    return;
                }
            }

            formData.append('exam_id', <?php echo $exam_id; ?>);
            formData.append('session_id', <?php echo $session_id; ?>);
            formData.append('answers', JSON.stringify(answers));

            // Show loading state
            const submitBtn = document.querySelector('.submit-btn') || document.getElementById('submitBtn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Submitting...';
            }

            // Stop timer
            clearInterval(timerInterval);

            fetch('submit_exam.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        if (data.has_theory) {
                            alert(`Exam submitted successfully!\n\nScore: ${data.score}%\nGrade: ${data.grade}\nCorrect: ${data.correct}/${data.total}\nAnswered: ${data.answered}/${data.total}\n\nYou will now see the theory questions.`);
                            window.location.href = `theory_questions.php?exam_id=${data.exam_id}`;
                        } else {
                            alert(`Exam submitted successfully!\n\nScore: ${data.score}%\nGrade: ${data.grade}\nCorrect: ${data.correct}/${data.total}\nAnswered: ${data.answered}/${data.total}`);
                            window.location.href = 'index.php';
                        }
                    } else {
                        alert('Error submitting exam: ' + data.message);
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.textContent = 'Submit Exam';
                        }
                        // Restart timer if submission failed
                        timerInterval = setInterval(updateTimer, 1000);
                    }
                })
                .catch(error => {
                    alert('Network error submitting exam. Please check your connection and try again.');
                    console.error('Error:', error);
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Submit Exam';
                    }
                    // Restart timer if submission failed
                    timerInterval = setInterval(updateTimer, 1000);
                });
        }

        // Prevent accidental navigation
        window.addEventListener('beforeunload', function(e) {
            e.preventDefault();
            e.returnValue = '';
        });

        // Initialize the first question
        showQuestion(1);
    </script>
</body>

</html>