<?php
session_start();

// DB connection
$conn = new mysqli("localhost", "root", "", "elms_db");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

function isLoggedIn() {
    return isset($_SESSION['user']);
}

function redirectToHome() {
    header("Location: index.php");
    exit;
}

function isInstructor() {
    return isLoggedIn() && $_SESSION['user']['role'] === 'instructor';
}

function isStudent() {
    return isLoggedIn() && $_SESSION['user']['role'] === 'student';
}

function isAdmin() {
    return isLoggedIn() && $_SESSION['user']['role'] === 'admin';
}

// REGISTER
if (isset($_POST['register'])) {
    $name = $conn->real_escape_string($_POST['name']);
    $email = $conn->real_escape_string($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = $conn->real_escape_string($_POST['role']);

    if (in_array($role, ['student','instructor','admin'])) {
        $sql = "INSERT INTO users (name, email, password, role) VALUES ('$name', '$email', '$password', '$role')";
        if ($conn->query($sql)) {
            $_SESSION['message'] = "Registration successful! Please login.";
            header("Location: index.php?page=login");
            exit;
        } else {
            $error = "Registration failed: " . $conn->error;
        }
    } else {
        $error = "Invalid role selected.";
    }
}

// LOGIN
if (isset($_POST['login'])) {
    $email = $conn->real_escape_string($_POST['email']);
    $password = $_POST['password'];

    $sql = "SELECT * FROM users WHERE email='$email' LIMIT 1";
    $result = $conn->query($sql);
    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['user'] = [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role']
            ];
            redirectToHome();
        } else {
            $error = "Invalid email or password.";
        }
    } else {
        $error = "Invalid email or password.";
    }
}

// LOGOUT
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// POST ACTIONS after login
if (isLoggedIn()) {

    $userId = $_SESSION['user']['id'];

    // Create course (instructor)
    if (isset($_POST['create_course']) && isInstructor()) {
        $title = $conn->real_escape_string($_POST['title']);
        $desc = $conn->real_escape_string($_POST['description']);
        $sql = "INSERT INTO courses (title, description, instructor_id) VALUES ('$title', '$desc', $userId)";
        if ($conn->query($sql)) {
            $success = "Course created successfully.";
        } else {
            $error = "Failed to create course: " . $conn->error;
        }
    }

    // Enroll in course (student)
    if (isset($_POST['enroll']) && isStudent()) {
        $courseId = intval($_POST['course_id']);
        // Check duplicate enrollment
        $check = $conn->query("SELECT * FROM enrollments WHERE user_id=$userId AND course_id=$courseId");
        if ($check->num_rows === 0) {
            $conn->query("INSERT INTO enrollments (user_id, course_id) VALUES ($userId, $courseId)");
            $success = "Enrolled in course successfully.";
        } else {
            $error = "You are already enrolled in this course.";
        }
    }

    // Create lesson (instructor)
    if (isset($_POST['create_lesson']) && isInstructor()) {
        $courseId = intval($_POST['course_id']);
        $title = $conn->real_escape_string($_POST['title']);
        $content = $conn->real_escape_string($_POST['content']);
        // Verify instructor owns the course
        $verify = $conn->query("SELECT * FROM courses WHERE id=$courseId AND instructor_id=$userId");
        if ($verify->num_rows === 1) {
            $conn->query("INSERT INTO lessons (course_id, title, content) VALUES ($courseId, '$title', '$content')");
            $success = "Lesson created successfully.";
        } else {
            $error = "You don't have permission to add lessons to this course.";
        }
    }

    // Create quiz (instructor)
    if (isset($_POST['create_quiz']) && isInstructor()) {
        $lessonId = intval($_POST['lesson_id']);
        $question = $conn->real_escape_string($_POST['question']);
        $a = $conn->real_escape_string($_POST['option_a']);
        $b = $conn->real_escape_string($_POST['option_b']);
        $c = $conn->real_escape_string($_POST['option_c']);
        $d = $conn->real_escape_string($_POST['option_d']);
        $correct = $_POST['correct_option'];

        // Verify instructor owns the course linked to lesson
        $verify = $conn->query("SELECT l.*, c.instructor_id FROM lessons l JOIN courses c ON l.course_id=c.id WHERE l.id=$lessonId AND c.instructor_id=$userId");
        if ($verify->num_rows === 1 && in_array($correct, ['a','b','c','d'])) {
            $conn->query("INSERT INTO quizzes (lesson_id, question, option_a, option_b, option_c, option_d, correct_option) VALUES ($lessonId, '$question', '$a', '$b', '$c', '$d', '$correct')");
            $success = "Quiz created successfully.";
        } else {
            $error = "You don't have permission to add quizzes or invalid correct option.";
        }
    }

    // Take quiz (student)
    if (isset($_POST['take_quiz']) && isStudent()) {
        $quizId = intval($_POST['quiz_id']);
        $selected = $_POST['selected_option'];
        if (!in_array($selected, ['a','b','c','d'])) {
            $error = "Invalid option selected.";
        } else {
            // Get correct option
            $res = $conn->query("SELECT correct_option FROM quizzes WHERE id=$quizId");
            if ($res && $res->num_rows === 1) {
                $correct = $res->fetch_assoc()['correct_option'];
                $isCorrect = ($selected === $correct) ? 1 : 0;
                $conn->query("INSERT INTO quiz_attempts (user_id, quiz_id, selected_option, is_correct) VALUES ($userId, $quizId, '$selected', $isCorrect)");
                $success = $isCorrect ? "Correct answer! Well done." : "Incorrect answer. Try next quiz.";
            } else {
                $error = "Quiz not found.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>ELMS - Dashboard</title>
<style>
  body {
    margin: 0; padding: 0;
    font-family: Arial, sans-serif;
    background: url('https://images.unsplash.com/photo-1504384308090-c894fdcc538d?auto=format&fit=crop&w=1350&q=80') no-repeat center center fixed;
    background-size: cover;
    color: #fff;
  }
  .container {
    background: rgba(0,0,0,0.85);
    max-width: 900px;
    margin: 20px auto;
    padding: 25px;
    border-radius: 10px;
  }
  h1,h2,h3 {
    margin-top: 0;
  }
  input, select, textarea, button {
    width: 100%;
    padding: 8px 10px;
    margin-bottom: 10px;
    border-radius: 5px;
    border: none;
    font-size: 1rem;
  }
  textarea { resize: vertical; }
  button {
    background: #0b79d0;
    color: white;
    cursor: pointer;
    font-weight: bold;
    border: none;
    transition: background-color 0.3s ease;
  }
  button:hover { background: #095a9c; }
  .flex-row {
    display: flex;
    gap: 20px;
  }
  .flex-child {
    flex: 1;
  }
  .error {
    background: #d9534f;
    padding: 10px;
    border-radius: 5px;
    margin-bottom: 15px;
    text-align: center;
  }
  .message {
    background: #5cb85c;
    padding: 10px;
    border-radius: 5px;
    margin-bottom: 15px;
    text-align: center;
  }
  .course-card {
    background: #222;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 15px;
  }
  a {
    color: #0b79d0;
    text-decoration: none;
  }
  a:hover { text-decoration: underline; }
  select, input[type=radio] {
    width: auto;
  }
  label {
    margin-right: 10px;
  }
</style>
</head>
<body>
<div class="container">

<?php if (!isLoggedIn()): ?>

    <h1 class="animated-welcome">Welcome to E-Learning Management System</h1>
    <p><a href="index.php?page=login">Login</a> or <a href="index.php?page=register">Register</a> to continue.</p>

<?php else: ?>

    <h1>Welcome, <?php echo htmlspecialchars($_SESSION['user']['name']); ?> (<?php echo $_SESSION['user']['role']; ?>)</h1>
    <p><a href="index.php?logout=1" style="color:#f00;">Logout</a></p>

    <?php if (isset($error)): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if (isset($success)): ?>
        <div class="message"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <hr>

    <!-- COURSES -->
    <h2>Courses</h2>

    <?php if (isInstructor()): ?>
        <h3>Create New Course</h3>
        <form method="POST" action="">
            <input type="text" name="title" placeholder="Course Title" required />
            <textarea name="description" placeholder="Course Description" rows="3" required></textarea>
            <button type="submit" name="create_course">Create Course</button>
        </form>
    <?php endif; ?>

    <?php
    // List all courses with instructor name
    $courses_res = $conn->query("SELECT c.*, u.name AS instructor_name FROM courses c JOIN users u ON c.instructor_id = u.id ORDER BY c.id DESC");
    if ($courses_res->num_rows === 0) {
        echo "<p>No courses available yet.</p>";
    } else {
        while ($course = $courses_res->fetch_assoc()) {
            echo '<div class="course-card">';
            echo '<h3>' . htmlspecialchars($course['title']) . '</h3>';
            echo '<p><strong>Instructor:</strong> ' . htmlspecialchars($course['instructor_name']) . '</p>';
            echo '<p>' . nl2br(htmlspecialchars($course['description'])) . '</p>';

            if (isStudent()) {
                // Check if enrolled
                $checkEnroll = $conn->query("SELECT * FROM enrollments WHERE user_id=$userId AND course_id=" . intval($course['id']));
                if ($checkEnroll->num_rows > 0) {
                    echo "<p style='color:#0f0;'>You are enrolled in this course.</p>";
                } else {
                    echo '<form method="POST" action="" style="margin-top:10px;">';
                    echo '<input type="hidden" name="course_id" value="' . intval($course['id']) . '">';
                    echo '<button type="submit" name="enroll">Enroll in Course</button>';
                    echo '</form>';
                }
            }

            if (isInstructor() && $course['instructor_id'] == $userId) {
                echo '<p><a href="?view_course=' . intval($course['id']) . '">Manage Course (Lessons & Quizzes)</a></p>';
            }
            echo '</div>';
        }
    }
    ?>

    <?php
    // COURSE MANAGEMENT: Lessons & Quizzes (Instructor only)
    if (isInstructor() && isset($_GET['view_course'])):
        $courseId = intval($_GET['view_course']);

        // Verify ownership
        $verify = $conn->query("SELECT * FROM courses WHERE id=$courseId AND instructor_id=$userId");
        if ($verify->num_rows !== 1) {
            echo '<p style="color:#f00;">Access denied to this course.</p>';
        } else {
            $course = $verify->fetch_assoc();
            echo "<hr><h2>Manage Course: " . htmlspecialchars($course['title']) . "</h2>";

            // Create Lesson Form
            ?>
            <h3>Add Lesson</h3>
            <form method="POST" action="">
                <input type="hidden" name="course_id" value="<?php echo $courseId; ?>" />
                <input type="text" name="title" placeholder="Lesson Title" required />
                <textarea name="content" placeholder="Lesson Content" rows="3" required></textarea>
                <button type="submit" name="create_lesson">Add Lesson</button>
            </form>
            <?php

            // List lessons
            $lessons = $conn->query("SELECT * FROM lessons WHERE course_id=$courseId ORDER BY id DESC");
            if ($lessons->num_rows === 0) {
                echo "<p>No lessons added yet.</p>";
            } else {
                while ($lesson = $lessons->fetch_assoc()) {
                    echo '<div class="course-card">';
                    echo '<h4>Lesson: ' . htmlspecialchars($lesson['title']) . '</h4>';
                    echo '<p>' . nl2br(htmlspecialchars($lesson['content'])) . '</p>';

                    // Create Quiz Form under this lesson
                    ?>
                    <details>
                    <summary>Add Quiz Question</summary>
                    <form method="POST" action="" style="margin-top:10px;">
                        <input type="hidden" name="lesson_id" value="<?php echo $lesson['id']; ?>" />
                        <textarea name="question" placeholder="Quiz Question" required></textarea>
                        <input type="text" name="option_a" placeholder="Option A" required />
                        <input type="text" name="option_b" placeholder="Option B" required />
                        <input type="text" name="option_c" placeholder="Option C" required />
                        <input type="text" name="option_d" placeholder="Option D" required />
                        <label>Correct Option:
                            <select name="correct_option" required>
                                <option value="">Select</option>
                                <option value="a">A</option>
                                <option value="b">B</option>
                                <option value="c">C</option>
                                <option value="d">D</option>
                            </select>
                        </label>
                        <button type="submit" name="create_quiz">Add Quiz</button>
                    </form>
                    </details>
                    <?php

                    // List quizzes for lesson
                    $quizzes = $conn->query("SELECT * FROM quizzes WHERE lesson_id=" . intval($lesson['id']) . " ORDER BY id DESC");
                    if ($quizzes->num_rows === 0) {
                        echo "<p>No quizzes for this lesson.</p>";
                    } else {
                        echo "<p><strong>Quizzes:</strong></p><ul>";
                        while ($quiz = $quizzes->fetch_assoc()) {
                            echo "<li>" . htmlspecialchars($quiz['question']) . "</li>";
                        }
                        echo "</ul>";
                    }
                    echo '</div>';
                }
            }

            echo '<p><a href="index.php">Back to dashboard</a></p>';
        }
    endif;
    ?>

    <!-- STUDENT: Show enrolled courses, lessons, quizzes -->
    <?php if (isStudent()): ?>
        <hr>
        <h2>Your Enrolled Courses</h2>
        <?php
        $enrolled = $conn->query("SELECT c.* FROM courses c JOIN enrollments e ON c.id = e.course_id WHERE e.user_id=$userId ORDER BY c.id DESC");
        if ($enrolled->num_rows === 0) {
            echo "<p>You are not enrolled in any courses yet.</p>";
        } else {
            while ($c = $enrolled->fetch_assoc()) {
                echo '<div class="course-card">';
                echo '<h3>' . htmlspecialchars($c['title']) . '</h3>';

                // List lessons under course
                $lessons = $conn->query("SELECT * FROM lessons WHERE course_id=" . intval($c['id']));
                if ($lessons->num_rows === 0) {
                    echo "<p>No lessons yet.</p>";
                } else {
                    while ($lesson = $lessons->fetch_assoc()) {
                        echo '<h4>Lesson: ' . htmlspecialchars($lesson['title']) . '</h4>';
                        echo '<p>' . nl2br(htmlspecialchars($lesson['content'])) . '</p>';

                        // Show quizzes for lesson with quiz taking form
                        $quizzes = $conn->query("SELECT * FROM quizzes WHERE lesson_id=" . intval($lesson['id']));
                        if ($quizzes->num_rows === 0) {
                            echo "<p>No quizzes for this lesson.</p>";
                        } else {
                            while ($quiz = $quizzes->fetch_assoc()) {
                                // Check if already attempted
                                $attemptCheck = $conn->query("SELECT * FROM quiz_attempts WHERE user_id=$userId AND quiz_id=" . intval($quiz['id']));
                                $attempt = $attemptCheck->fetch_assoc();
                                echo '<div style="background:#333;padding:10px;border-radius:6px;margin-bottom:10px;">';
                                echo '<p><strong>Quiz:</strong> ' . htmlspecialchars($quiz['question']) . '</p>';

                                if ($attemptCheck->num_rows > 0) {
                                    echo '<p>Your Answer: ' . strtoupper($attempt['selected_option']) . ' - ' . ($attempt['is_correct'] ? '<span style="color:#0f0;">Correct</span>' : '<span style="color:#f00;">Incorrect</span>') . '</p>';
                                } else {
                                    // Show form to take quiz
                                    ?>
                                    <form method="POST" action="">
                                        <input type="hidden" name="quiz_id" value="<?php echo $quiz['id']; ?>" />
                                        <label><input type="radio" name="selected_option" value="a" required> A: <?php echo htmlspecialchars($quiz['option_a']); ?></label><br>
                                        <label><input type="radio" name="selected_option" value="b"> B: <?php echo htmlspecialchars($quiz['option_b']); ?></label><br>
                                        <label><input type="radio" name="selected_option" value="c"> C: <?php echo htmlspecialchars($quiz['option_c']); ?></label><br>
                                        <label><input type="radio" name="selected_option" value="d"> D: <?php echo htmlspecialchars($quiz['option_d']); ?></label><br>
                                        <button type="submit" name="take_quiz">Submit Answer</button>
                                    </form>
                                    <?php
                                }
                                echo '</div>';
                            }
                        }
                    }
                }

                echo '</div>';
            }
        }
        ?>
    <?php endif; ?>

<?php endif; ?>

<!-- LOGIN & REGISTER PAGES -->

<?php if (!isLoggedIn() && isset($_GET['page'])): ?>

    <?php if ($_GET['page'] === 'login'): ?>
        <h2>Login</h2>
        <?php if (isset($_SESSION['message'])) { echo '<div class="message">'.htmlspecialchars($_SESSION['message']).'</div>'; unset($_SESSION['message']); } ?>
        <form method="POST" action="">
            <input type="email" name="email" placeholder="Email" required />
            <input type="password" name="password" placeholder="Password" required />
            <button type="submit" name="login">Login</button>
        </form>
        <p>Don't have an account? <a href="index.php?page=register">Register here</a></p>

    <?php elseif ($_GET['page'] === 'register'): ?>
        <h2>Register</h2>
        <?php if (isset($error)) echo '<div class="error">'.htmlspecialchars($error).'</div>'; ?>
        <form method="POST" action="">
            <input type="text" name="name" placeholder="Full Name" required />
            <input type="email" name="email" placeholder="Email" required />
            <input type="password" name="password" placeholder="Password" required />
            <label for="role">Select Role:</label>
            <select name="role" required>
                <option value="">--Select Role--</option>
                <option value="student">Student</option>
                <option value="instructor">Instructor</option>
                <option value="admin">Admin</option>
            </select>
            <button type="submit" name="register">Register</button>
        </form>
        <p>Already have an account? <a href="index.php?page=login">Login here</a></p>

    <?php endif; ?>

<?php endif; ?>

<script>
// Simple welcome animation (fade in)
document.addEventListener('DOMContentLoaded', () => {
    const el = document.querySelector('.animated-welcome');
    if(el) {
        el.style.opacity = 0;
        let op = 0;
        let interval = setInterval(() => {
            if(op >= 1) clearInterval(interval);
            el.style.opacity = op;
            op += 0.02;
        }, 20);
    }
});
</script>

</div>
</body>
</html>


