<?php
// student/view-result.php
session_start();
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Check if student is logged in
if (!isStudentLoggedIn()) {
    redirect('../index.php', 'Please login to view results', 'warning');
}

$student_id = $_SESSION['student_id'];
$exam_id = $_GET['exam_id'] ?? 0;

if (!$exam_id) {
    redirect('exam-list.php', 'No exam specified', 'danger');
}

try {
    // Get exam result
    $stmt = $pdo->prepare("
        SELECT r.*, e.*, s.subject_name, st.full_name as student_name, 
               st.admission_number, st.class,
               es.start_time, es.end_time,
               TIMESTAMPDIFF(MINUTE, es.start_time, es.end_time) as time_taken_minutes,
               r.correct_count, r.total_questions
        FROM results r
        JOIN exams e ON r.exam_id = e.id
        JOIN subjects s ON e.subject_id = s.id
        JOIN students st ON r.student_id = st.id
        JOIN exam_sessions es ON r.exam_id = es.exam_id AND r.student_id = es.student_id
        WHERE r.exam_id = ? AND r.student_id = ?
        ORDER BY r.submitted_at DESC
        LIMIT 1
    ");
    $stmt->execute([$exam_id, $student_id]);
    $result = $stmt->fetch();

    if (!$result) {
        throw new Exception("No result found for this exam");
    }

    // Get all questions and student answers - IMPROVED QUERY
    $stmt = $pdo->prepare("
        SELECT oq.*, esq.session_id, es.objective_answers,
               JSON_UNQUOTE(JSON_EXTRACT(es.objective_answers, CONCAT('$.\"', oq.id, '\"'))) as extracted_answer
        FROM exam_session_questions esq
        JOIN objective_questions oq ON esq.question_id = oq.id
        JOIN exam_sessions es ON esq.session_id = es.id
        WHERE esq.session_id = (
            SELECT id FROM exam_sessions 
            WHERE exam_id = ? AND student_id = ? 
            ORDER BY start_time DESC LIMIT 1
        )
        ORDER BY esq.id
    ");
    $stmt->execute([$exam_id, $student_id]);
    $questions = $stmt->fetchAll();

    // Parse student answers - FIXED LOGIC
    $question_details = [];
    $calculated_correct_count = 0;

    foreach ($questions as $question) {
        // Get student answer from JSON or try alternative methods
        $student_answer = '';

        // Method 1: Try extracted JSON answer
        if (!empty($question['extracted_answer'])) {
            $student_answer = trim($question['extracted_answer']);
        }

        // Method 2: Parse the JSON directly
        if (empty($student_answer) && !empty($question['objective_answers'])) {
            $answers_json = $question['objective_answers'];
            // Try to decode the JSON
            $answers_array = json_decode($answers_json, true);
            if ($answers_array && isset($answers_array[$question['id']])) {
                $student_answer = trim($answers_array[$question['id']]);
            }
        }

        // Clean and standardize answers
        $student_answer = strtoupper(trim($student_answer));
        $correct_answer = strtoupper(trim($question['correct_answer']));

        // Validate answer is A, B, C, or D
        if (!in_array($student_answer, ['A', 'B', 'C', 'D'])) {
            $student_answer = ''; // Treat invalid answers as unanswered
        }

        // Determine if answer is correct
        $is_correct = false;
        if (!empty($student_answer) && $student_answer === $correct_answer) {
            $is_correct = true;
            $calculated_correct_count++;
        }

        $question_details[] = [
            'id' => $question['id'],
            'text' => $question['question_text'],
            'image' => $question['question_image'],
            'options' => [
                'A' => $question['option_a'],
                'B' => $question['option_b'],
                'C' => $question['option_c'],
                'D' => $question['option_d']
            ],
            'correct_answer' => $correct_answer,
            'student_answer' => $student_answer,
            'marks' => $question['marks'] ?? 1,
            'difficulty' => $question['difficulty_level'] ?? 'medium',
            'is_correct' => $is_correct
        ];
    }

    // Calculate statistics - USE CONSISTENT DATA
    $total_questions = count($question_details);

    // Prioritize calculated correct count, fall back to stored value
    if ($calculated_correct_count > 0) {
        $correct_answers_count = $calculated_correct_count;
    } else {
        $correct_answers_count = $result['correct_count'] ?? 0;
    }

    $wrong_answers_count = $total_questions - $correct_answers_count;
    $score_percentage = $result['percentage'];
    $total_marks_obtained = $result['total_score'];

    // Calculate max possible marks
    $max_marks = array_sum(array_column($question_details, 'marks'));

    // Calculate by difficulty
    $difficulty_stats = [
        'easy' => ['total' => 0, 'correct' => 0],
        'medium' => ['total' => 0, 'correct' => 0],
        'hard' => ['total' => 0, 'correct' => 0]
    ];

    foreach ($question_details as $question) {
        $difficulty = $question['difficulty'];
        if (isset($difficulty_stats[$difficulty])) {
            $difficulty_stats[$difficulty]['total']++;
            if ($question['is_correct']) {
                $difficulty_stats[$difficulty]['correct']++;
            }
        }
    }

    // Calculate time efficiency
    $time_taken_minutes = $result['time_taken_minutes'] ?? 0;
    $exam_duration = $result['duration_minutes'] ?? 60;
    $time_efficiency = $exam_duration > 0 ?
        round(($time_taken_minutes / $exam_duration) * 100, 1) : 0;

    // Get class statistics for comparison
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_students,
            AVG(percentage) as class_average,
            MAX(percentage) as class_max,
            MIN(percentage) as class_min
        FROM results 
        WHERE exam_id = ?
    ");
    $stmt->execute([$exam_id]);
    $class_stats = $stmt->fetch();

    // Get student's position in class
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as position
        FROM results r1
        WHERE r1.exam_id = ? 
        AND r1.percentage > (
            SELECT percentage 
            FROM results r2 
            WHERE r2.exam_id = ? AND r2.student_id = ?
        )
    ");
    $stmt->execute([$exam_id, $exam_id, $student_id]);
    $position_result = $stmt->fetch();
    $position = ($position_result['position'] ?? 0) + 1;

    // Get student's recent performance
    $stmt = $pdo->prepare("
        SELECT e.exam_name, r.percentage, r.grade, r.submitted_at
        FROM results r
        JOIN exams e ON r.exam_id = e.id
        WHERE r.student_id = ? AND r.exam_id != ?
        ORDER BY r.submitted_at DESC
        LIMIT 5
    ");
    $stmt->execute([$student_id, $exam_id]);
    $recent_results = $stmt->fetchAll();
} catch (Exception $e) {
    redirect('exam-list.php', $e->getMessage(), 'danger');
}

// Helper function to get grade color
function getGradeColor($grade)
{
    switch (strtoupper($grade)) {
        case 'A':
            return '#27ae60';
        case 'B':
            return '#2ecc71';
        case 'C':
            return '#f39c12';
        case 'D':
            return '#e67e22';
        case 'E':
            return '#e74c3c';
        case 'F':
            return '#c0392b';
        default:
            return '#95a5a6';
    }
}

// Helper function to get performance message
function getPerformanceMessage($percentage)
{
    if ($percentage >= 80) {
        return "Excellent performance! Keep up the great work! 🎉";
    } elseif ($percentage >= 70) {
        return "Very good! You're doing well. 👍";
    } elseif ($percentage >= 60) {
        return "Good effort! There's room for improvement. 💪";
    } elseif ($percentage >= 50) {
        return "Satisfactory. Focus on weak areas. 📚";
    } else {
        return "Needs improvement. Consider revising the topics. 📖";
    }
}

// Display header
echo displayHeader('Exam Results');
?>

<div class="main-container">
    <!-- Header -->
    <div style="margin-bottom: 30px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; margin-bottom: 20px;">
            <div>
                <h1 style="color: <?php echo COLOR_PRIMARY; ?>; margin: 0;">Exam Results</h1>
                <p style="color: #666; margin: 5px 0 0 0;">
                    Detailed analysis of your performance
                </p>
            </div>
            <div>
                <a href="exam-list.php" class="btn" style="background: <?php echo COLOR_SECONDARY; ?>;">Back to Exams</a>
                <a href="view-results.php" class="btn" style="background: <?php echo COLOR_SUCCESS; ?>;">All Results</a>
                <button onclick="window.print()" class="btn" style="background: <?php echo COLOR_WARNING; ?>;">Print Result</button>
            </div>
        </div>
    </div>

    <?php displayFlashMessage(); ?>

    <!-- Score Summary Card -->
    <div class="content-card" style="background: linear-gradient(135deg, <?php echo getGradeColor($result['grade']); ?>, #2c3e50); color: white;">
        <div style="display: grid; grid-template-columns: 1fr auto; gap: 30px; align-items: center;">
            <div>
                <h2 style="margin: 0 0 10px 0; font-size: 1.8em;"><?php echo htmlspecialchars($result['exam_name']); ?></h2>
                <p style="margin: 0 0 15px 0; opacity: 0.9; font-size: 1.1em;">
                    Subject: <?php echo htmlspecialchars($result['subject_name']); ?> |
                    Date: <?php echo date('F j, Y', strtotime($result['submitted_at'])); ?>
                </p>

                <div style="display: flex; gap: 30px; margin-top: 20px;">
                    <div>
                        <div style="font-size: 3em; font-weight: bold; line-height: 1;">
                            <?php echo round($score_percentage, 1); ?>%
                        </div>
                        <div style="opacity: 0.9;">Overall Score</div>
                    </div>

                    <div>
                        <div style="font-size: 2.5em; font-weight: bold; line-height: 1;">
                            <?php echo htmlspecialchars($result['grade']); ?>
                        </div>
                        <div style="opacity: 0.9;">Grade</div>
                    </div>

                    <div>
                        <div style="font-size: 2.5em; font-weight: bold; line-height: 1;">
                            <?php echo $correct_answers_count; ?>/<?php echo $total_questions; ?>
                        </div>
                        <div style="opacity: 0.9;">Correct Answers</div>
                    </div>
                </div>
            </div>

            <div style="text-align: center;">
                <div style="width: 150px; height: 150px; position: relative;">
                    <svg width="150" height="150" viewBox="0 0 36 36">
                        <path d="M18 2.0845
                            a 15.9155 15.9155 0 0 1 0 31.831
                            a 15.9155 15.9155 0 0 1 0 -31.831"
                            fill="none"
                            stroke="rgba(255,255,255,0.2)"
                            stroke-width="3"
                            stroke-dasharray="100, 100" />
                        <path d="M18 2.0845
                            a 15.9155 15.9155 0 0 1 0 31.831
                            a 15.9155 15.9155 0 0 1 0 -31.831"
                            fill="none"
                            stroke="white"
                            stroke-width="3"
                            stroke-dasharray="<?php echo $score_percentage; ?>, 100"
                            stroke-linecap="round" />
                        <text x="18" y="22" text-anchor="middle" fill="white" font-size="8" font-weight="bold">
                            <?php echo round($score_percentage); ?>%
                        </text>
                    </svg>
                </div>
                <p style="margin-top: 10px; opacity: 0.9;">Score Percentage</p>
            </div>
        </div>
    </div>

    <!-- Performance Message -->
    <div class="content-card" style="background: #f8f9fa; border-left: 4px solid <?php echo getGradeColor($result['grade']); ?>;">
        <div style="display: flex; align-items: center; gap: 15px;">
            <div style="font-size: 2em;">🏆</div>
            <div>
                <h3 style="margin: 0 0 5px 0; color: <?php echo COLOR_PRIMARY; ?>;">Performance Analysis</h3>
                <p style="margin: 0; color: #666;">
                    <?php echo getPerformanceMessage($score_percentage); ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Statistics Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
        <!-- Time Statistics -->
        <div class="content-card">
            <h3 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 15px;">⏱️ Time Statistics</h3>
            <div style="display: grid; gap: 10px;">
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: #666;">Time Taken:</span>
                    <span style="font-weight: bold;"><?php echo $time_taken_minutes; ?> mins</span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: #666;">Exam Duration:</span>
                    <span style="font-weight: bold;"><?php echo $exam_duration; ?> mins</span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: #666;">Time Efficiency:</span>
                    <span style="font-weight: bold; color: <?php echo $time_efficiency <= 80 ? COLOR_SUCCESS : COLOR_WARNING; ?>;">
                        <?php echo $time_efficiency; ?>%
                    </span>
                </div>
            </div>
        </div>

        <!-- Marks Breakdown -->
        <div class="content-card">
            <h3 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 15px;">📊 Marks Breakdown</h3>
            <div style="display: grid; gap: 10px;">
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: #666;">Marks Obtained:</span>
                    <span style="font-weight: bold; color: <?php echo COLOR_SUCCESS; ?>;">
                        <?php echo $total_marks_obtained; ?>
                    </span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: #666;">Maximum Marks:</span>
                    <span style="font-weight: bold;"><?php echo $max_marks; ?></span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: #666;">Average per Question:</span>
                    <span style="font-weight: bold;">
                        <?php echo $total_questions > 0 ? round($total_marks_obtained / $total_questions, 2) : 0; ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Class Comparison -->
        <div class="content-card">
            <h3 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 15px;">📈 Class Comparison</h3>
            <div style="display: grid; gap: 10px;">
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: #666;">Your Position:</span>
                    <span style="font-weight: bold; color: <?php echo COLOR_SUCCESS; ?>;">
                        <?php echo $position; ?><?php echo getOrdinalSuffix($position); ?>
                    </span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: #666;">Class Average:</span>
                    <span style="font-weight: bold;"><?php echo round($class_stats['class_average'] ?? 0, 1); ?>%</span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: #666;">Highest Score:</span>
                    <span style="font-weight: bold; color: <?php echo COLOR_SUCCESS; ?>;">
                        <?php echo round($class_stats['class_max'] ?? 0, 1); ?>%
                    </span>
                </div>
            </div>
        </div>

        <!-- Difficulty Analysis -->
        <div class="content-card">
            <h3 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 15px;">🎯 Difficulty Analysis</h3>
            <div style="display: grid; gap: 10px;">
                <?php foreach ($difficulty_stats as $difficulty => $stats):
                    $percentage = $stats['total'] > 0 ? round(($stats['correct'] / $stats['total']) * 100, 1) : 0;
                    $color = $percentage >= 70 ? COLOR_SUCCESS : ($percentage >= 50 ? COLOR_WARNING : COLOR_DANGER);
                ?>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #666; text-transform: capitalize;"><?php echo $difficulty; ?>:</span>
                        <span style="font-weight: bold; color: <?php echo $color; ?>;">
                            <?php echo $stats['correct']; ?>/<?php echo $stats['total']; ?> (<?php echo $percentage; ?>%)
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Answer Review Section -->
    <div class="content-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="color: <?php echo COLOR_PRIMARY; ?>; margin: 0;">📝 Question-by-Question Review</h2>
            <div>
                <span style="color: #666;">Showing <?php echo $total_questions; ?> questions</span>
                <button class="btn" style="background: <?php echo COLOR_SECONDARY; ?>; margin-left: 10px; padding: 8px 15px;"
                    onclick="toggleAllExplanations()">Toggle All Explanations</button>
            </div>
        </div>

        <?php if (empty($question_details)): ?>
            <div style="text-align: center; padding: 40px; color: #666;">
                <p>No question details available for this exam.</p>
            </div>
        <?php else: ?>
            <div id="questionReview">
                <?php foreach ($question_details as $index => $question):
                    $is_correct = $question['is_correct'];
                    $student_answer = $question['student_answer'];
                    $correct_answer = $question['correct_answer'];
                    $has_image = !empty($question['image']);
                    $is_answered = !empty($student_answer);
                ?>
                    <div class="question-review-item" style="margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid #eee;">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px;">
                            <div>
                                <h3 style="margin: 0; color: <?php echo COLOR_PRIMARY; ?>;">
                                    Question <?php echo $index + 1; ?>
                                    <span style="font-size: 0.8em; color: #666;">(<?php echo ucfirst($question['difficulty']); ?>)</span>
                                </h3>
                                <div style="margin-top: 5px;">
                                    <span style="display: inline-block; padding: 3px 10px; border-radius: 15px; 
                                  background: <?php echo $is_correct ? '#d5f4e6' : '#f8d7da'; ?>; 
                                  color: <?php echo $is_correct ? '#155724' : '#721c24'; ?>; 
                                  font-size: 0.85em; font-weight: 600;">
                                        <?php
                                        if (!$is_answered) {
                                            echo '⏺️ Not Answered';
                                        } elseif ($is_correct) {
                                            echo '✓ Correct';
                                        } else {
                                            echo '✗ Incorrect';
                                        }
                                        ?>
                                    </span>
                                    <span style="margin-left: 10px; font-size: 0.9em; color: #666;">
                                        Marks: <?php echo $question['marks']; ?>
                                    </span>
                                </div>
                            </div>

                            <div style="text-align: right;">
                                <button class="btn" style="background: <?php echo COLOR_SECONDARY; ?>; padding: 5px 10px; font-size: 0.85em;"
                                    onclick="toggleExplanation(<?php echo $index; ?>)">
                                    Show Explanation
                                </button>
                            </div>
                        </div>

                        <div class="question-text" style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                            <?php echo nl2br(htmlspecialchars($question['text'])); ?>
                        </div>

                        <?php if ($has_image): ?>
                            <div style="margin-bottom: 20px; text-align: center;">
                                <img src="<?php echo htmlspecialchars($question['image']); ?>"
                                    alt="Question Image"
                                    style="max-width: 100%; height: auto; max-height: 300px; border-radius: 8px; border: 1px solid #ddd;">
                            </div>
                        <?php endif; ?>

                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-bottom: 20px;">
                            <?php foreach (['A', 'B', 'C', 'D'] as $option):
                                $is_student_answer = $student_answer === $option;
                                $is_correct_option = $correct_answer === $option;
                                $bg_color = '#f8f9fa';
                                $border_color = '#e9ecef';
                                $text_color = '#666';

                                if ($is_student_answer && $is_correct_option) {
                                    $bg_color = '#d5f4e6';
                                    $border_color = '#27ae60';
                                    $text_color = '#155724';
                                } elseif ($is_student_answer && !$is_correct_option) {
                                    $bg_color = '#f8d7da';
                                    $border_color = '#e74c3c';
                                    $text_color = '#721c24';
                                } elseif ($is_correct_option) {
                                    $bg_color = '#d1ecf1';
                                    $border_color = '#3498db';
                                    $text_color = '#0c5460';
                                }
                            ?>
                                <div style="padding: 15px; background: <?php echo $bg_color; ?>; 
                                border: 2px solid <?php echo $border_color; ?>; 
                                border-radius: 8px; color: <?php echo $text_color; ?>;">
                                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                                        <div style="width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;
                                      background: <?php echo $border_color; ?>; color: white; border-radius: 50%; font-weight: bold;">
                                            <?php echo $option; ?>
                                        </div>
                                        <div style="font-weight: 600;">
                                            <?php if ($is_student_answer && $is_correct_option): ?>
                                                Your Answer ✓
                                            <?php elseif ($is_student_answer && !$is_correct_option): ?>
                                                Your Answer ✗
                                            <?php elseif ($is_correct_option): ?>
                                                Correct Answer
                                            <?php else: ?>
                                                Option <?php echo $option; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div style="font-size: 0.95em;">
                                        <?php echo nl2br(htmlspecialchars($question['options'][$option])); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div id="explanation-<?php echo $index; ?>" class="explanation" style="display: none; 
                    margin-top: 15px; padding: 15px; background: #e8f4fc; border-radius: 8px; border-left: 4px solid <?php echo COLOR_SECONDARY; ?>;">
                            <h4 style="margin: 0 0 10px 0; color: <?php echo COLOR_PRIMARY; ?>;">Explanation:</h4>
                            <p style="margin: 0; color: #666;">
                                The correct answer is <strong><?php echo $correct_answer; ?></strong>.
                                <?php if ($is_answered): ?>
                                    <?php if ($is_correct): ?>
                                        You correctly selected <?php echo $student_answer; ?>.
                                    <?php else: ?>
                                        You selected <?php echo $student_answer; ?>, but the correct answer is <?php echo $correct_answer; ?>.
                                    <?php endif; ?>
                                <?php else: ?>
                                    You did not answer this question.
                                <?php endif; ?>
                                This question was worth <?php echo $question['marks']; ?> mark<?php echo $question['marks'] > 1 ? 's' : ''; ?>.
                            </p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Recent Performance -->
    <?php if (!empty($recent_results)): ?>
        <div class="content-card">
            <h2 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 20px;">📈 Recent Performance</h2>

            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: <?php echo COLOR_SECONDARY; ?>; color: white;">
                            <th style="padding: 12px; text-align: left;">Exam</th>
                            <th style="padding: 12px; text-align: left;">Date</th>
                            <th style="padding: 12px; text-align: left;">Score</th>
                            <th style="padding: 12px; text-align: left;">Grade</th>
                            <th style="padding: 12px; text-align: left;">Trend</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_results as $index => $recent):
                            $trend = '';
                            $trend_color = '';
                            if ($index > 0) {
                                $prev_score = $recent_results[$index - 1]['percentage'] ?? 0;
                                if ($recent['percentage'] > $prev_score) {
                                    $trend = '↑ Improving';
                                    $trend_color = COLOR_SUCCESS;
                                } elseif ($recent['percentage'] < $prev_score) {
                                    $trend = '↓ Declining';
                                    $trend_color = COLOR_DANGER;
                                } else {
                                    $trend = '→ Stable';
                                    $trend_color = COLOR_WARNING;
                                }
                            }
                        ?>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 12px; font-weight: 600;"><?php echo htmlspecialchars($recent['exam_name']); ?></td>
                                <td style="padding: 12px;"><?php echo date('M j, Y', strtotime($recent['submitted_at'])); ?></td>
                                <td style="padding: 12px;">
                                    <span style="font-weight: bold; color: <?php echo getScoreColor($recent['percentage']); ?>;">
                                        <?php echo round($recent['percentage'], 1); ?>%
                                    </span>
                                </td>
                                <td style="padding: 12px;">
                                    <span style="background: <?php echo getGradeColor($recent['grade']); ?>; 
                                  color: white; padding: 4px 10px; border-radius: 15px; font-size: 0.85em; font-weight: 600;">
                                        <?php echo htmlspecialchars($recent['grade']); ?>
                                    </span>
                                </td>
                                <td style="padding: 12px;">
                                    <span style="color: <?php echo $trend_color; ?>; font-weight: 600;">
                                        <?php echo $trend; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 20px; text-align: center;">
                <a href="view-results.php" class="btn" style="background: <?php echo COLOR_SECONDARY; ?>;">
                    View All Results
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Recommendations -->
    <div class="content-card" style="background: #fff8e1; border-left: 4px solid <?php echo COLOR_WARNING; ?>;">
        <h2 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 15px;">💡 Recommendations for Improvement</h2>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
            <?php if ($score_percentage < 50): ?>
                <div style="background: white; padding: 15px; border-radius: 8px;">
                    <h4 style="margin: 0 0 10px 0; color: <?php echo COLOR_DANGER; ?>;">High Priority Areas</h4>
                    <p style="margin: 0; color: #666; font-size: 0.9em;">
                        Focus on basic concepts. Review all incorrect answers and understand the fundamentals.
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($difficulty_stats['hard']['correct'] < $difficulty_stats['hard']['total'] * 0.5): ?>
                <div style="background: white; padding: 15px; border-radius: 8px;">
                    <h4 style="margin: 0 0 10px 0; color: <?php echo COLOR_WARNING; ?>;">Difficult Questions</h4>
                    <p style="margin: 0; color: #666; font-size: 0.9em;">
                        You struggled with hard questions. Practice more challenging problems.
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($time_efficiency > 90): ?>
                <div style="background: white; padding: 15px; border-radius: 8px;">
                    <h4 style="margin: 0 0 10px 0; color: <?php echo COLOR_SUCCESS; ?>;">Time Management</h4>
                    <p style="margin: 0; color: #666; font-size: 0.9em;">
                        Good time management! You completed the exam with time to spare.
                    </p>
                </div>
            <?php endif; ?>

            <div style="background: white; padding: 15px; border-radius: 8px;">
                <h4 style="margin: 0 0 10px 0; color: <?php echo COLOR_SECONDARY; ?>;">Next Steps</h4>
                <p style="margin: 0; color: #666; font-size: 0.9em;">
                    Review all incorrect answers. Take similar exams to improve. Focus on weak topics.
                </p>
            </div>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="content-card" style="text-align: center;">
        <div style="display: flex; justify-content: center; gap: 15px; flex-wrap: wrap;">
            <a href="exam-list.php" class="btn" style="background: <?php echo COLOR_SECONDARY; ?>;">
                Take Another Exam
            </a>
            <button onclick="window.print()" class="btn" style="background: <?php echo COLOR_WARNING; ?>;">
                Print Detailed Report
            </button>
            <a href="index.php" class="btn" style="background: <?php echo COLOR_SUCCESS; ?>;">
                Back to Dashboard
            </a>
        </div>
    </div>

</div>

<script>
    // Toggle explanation for a single question
    function toggleExplanation(index) {
        const explanation = document.getElementById('explanation-' + index);
        const button = explanation.previousElementSibling.querySelector('button');

        if (explanation.style.display === 'none' || explanation.style.display === '') {
            explanation.style.display = 'block';
            button.textContent = 'Hide Explanation';
            button.style.background = '<?php echo COLOR_DANGER; ?>';
        } else {
            explanation.style.display = 'none';
            button.textContent = 'Show Explanation';
            button.style.background = '<?php echo COLOR_SECONDARY; ?>';
        }
    }

    // Toggle all explanations
    function toggleAllExplanations() {
        const explanations = document.querySelectorAll('.explanation');
        const anyVisible = Array.from(explanations).some(exp => exp.style.display === 'block');

        explanations.forEach((explanation, index) => {
            const button = explanation.previousElementSibling.querySelector('button');
            if (anyVisible) {
                explanation.style.display = 'none';
                if (button) {
                    button.textContent = 'Show Explanation';
                    button.style.background = '<?php echo COLOR_SECONDARY; ?>';
                }
            } else {
                explanation.style.display = 'block';
                if (button) {
                    button.textContent = 'Hide Explanation';
                    button.style.background = '<?php echo COLOR_DANGER; ?>';
                }
            }
        });
    }

    // Highlight incorrect answers by default
    document.addEventListener('DOMContentLoaded', function() {
        // Show explanations for incorrect answers by default
        document.querySelectorAll('.question-review-item').forEach((item, index) => {
            const isCorrect = item.querySelector('span[style*="background: #d5f4e6"]') !== null;
            const isNotAnswered = item.querySelector('span:contains("Not Answered")') !== null;

            if (!isCorrect && !isNotAnswered) {
                toggleExplanation(index);
            }
        });
    });
</script>

<?php
// Helper function for ordinal suffixes
function getOrdinalSuffix($number)
{
    if ($number % 100 >= 11 && $number % 100 <= 13) {
        return 'th';
    }
    switch ($number % 10) {
        case 1:
            return 'st';
        case 2:
            return 'nd';
        case 3:
            return 'rd';
        default:
            return 'th';
    }
}

// Helper function for score color
function getScoreColor($score)
{
    if ($score >= 70) return COLOR_SUCCESS;
    if ($score >= 50) return COLOR_WARNING;
    return COLOR_DANGER;
}

// Display footer
echo displayFooter();
?>