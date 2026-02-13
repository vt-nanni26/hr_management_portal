<?php
// task_manage.php - Task Management for HR Dashboard
session_start();

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'emp_system');

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to UTF-8
$conn->set_charset("utf8mb4");

// Check if HR is logged in
if (!isset($_SESSION['hr_logged_in']) || $_SESSION['hr_logged_in'] !== true) {
    header('Location: hr_dashboard.php');
    exit();
}

$hr_id = $_SESSION['hr_id'] ?? 1;

// Handle form submissions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_task'])) {
        // Add new task
        $intern_id = intval($_POST['intern_id']);
        $title = $conn->real_escape_string($_POST['title']);
        $description = $conn->real_escape_string($_POST['description']);
        $deadline = $conn->real_escape_string($_POST['deadline']);
        $priority = $conn->real_escape_string($_POST['priority']);
        $assigned_by = $hr_id;
        $assigned_date = date('Y-m-d');
        
        $sql = "INSERT INTO intern_tasks (intern_id, title, description, assigned_by, assigned_date, deadline, priority, status) 
                VALUES ($intern_id, '$title', '$description', $assigned_by, '$assigned_date', '$deadline', '$priority', 'pending')";
        
        if ($conn->query($sql)) {
            $message = "Task added successfully!";
            $message_type = "success";
        } else {
            $message = "Error adding task: " . $conn->error;
            $message_type = "error";
        }
    } elseif (isset($_POST['update_task'])) {
        // Update task status
        $task_id = intval($_POST['task_id']);
        $status = $conn->real_escape_string($_POST['status']);
        $feedback = isset($_POST['feedback']) ? $conn->real_escape_string($_POST['feedback']) : NULL;
        $rating = isset($_POST['rating']) ? intval($_POST['rating']) : NULL;
        $completion_date = ($status == 'completed') ? date('Y-m-d') : NULL;
        
        $sql = "UPDATE intern_tasks SET 
                status = '$status',
                feedback = " . ($feedback ? "'$feedback'" : "NULL") . ",
                rating = " . ($rating ? $rating : "NULL") . ",
                completion_date = " . ($completion_date ? "'$completion_date'" : "NULL") . "
                WHERE id = $task_id";
        
        if ($conn->query($sql)) {
            $message = "Task updated successfully!";
            $message_type = "success";
        } else {
            $message = "Error updating task: " . $conn->error;
            $message_type = "error";
        }
    } elseif (isset($_POST['delete_task'])) {
        // Delete task
        $task_id = intval($_POST['task_id']);
        
        $sql = "DELETE FROM intern_tasks WHERE id = $task_id";
        
        if ($conn->query($sql)) {
            $message = "Task deleted successfully!";
            $message_type = "success";
        } else {
            $message = "Error deleting task: " . $conn->error;
            $message_type = "error";
        }
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$intern_filter = $_GET['intern_id'] ?? 'all';
$priority_filter = $_GET['priority'] ?? 'all';
$search_term = $_GET['search'] ?? '';

// Build WHERE clause for filters
$where_clauses = [];
if ($status_filter !== 'all') {
    $where_clauses[] = "t.status = '" . $conn->real_escape_string($status_filter) . "'";
}
if ($intern_filter !== 'all') {
    $where_clauses[] = "t.intern_id = " . intval($intern_filter);
}
if ($priority_filter !== 'all') {
    $where_clauses[] = "t.priority = '" . $conn->real_escape_string($priority_filter) . "'";
}
if (!empty($search_term)) {
    $search_term_escaped = $conn->real_escape_string($search_term);
    $where_clauses[] = "(t.title LIKE '%$search_term_escaped%' OR t.description LIKE '%$search_term_escaped%')";
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Get tasks with intern and assigner details
$tasks_query = "
    SELECT t.*, 
           i.intern_id, i.first_name as intern_first_name, i.last_name as intern_last_name, i.email as intern_email,
           e.first_name as assigner_first_name, e.last_name as assigner_last_name,
           d.name as department_name
    FROM intern_tasks t
    LEFT JOIN interns i ON t.intern_id = i.id
    LEFT JOIN employees e ON t.assigned_by = e.id
    LEFT JOIN departments d ON i.department_id = d.id
    $where_sql
    ORDER BY 
        CASE t.priority 
            WHEN 'high' THEN 1
            WHEN 'medium' THEN 2
            WHEN 'low' THEN 3
            ELSE 4
        END,
        t.deadline ASC,
        t.created_at DESC
";

$tasks_result = $conn->query($tasks_query);

// Get all interns for dropdown
$interns_query = "SELECT id, intern_id, first_name, last_name, department_id FROM interns WHERE internship_status = 'active' ORDER BY first_name, last_name";
$interns_result = $conn->query($interns_query);
$interns = [];
if ($interns_result) {
    while ($row = $interns_result->fetch_assoc()) {
        $interns[] = $row;
    }
}

// Get task statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_tasks,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_tasks,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tasks,
        SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_tasks,
        SUM(CASE WHEN deadline < CURDATE() AND status NOT IN ('completed', 'overdue') THEN 1 ELSE 0 END) as due_today
    FROM intern_tasks
";

$stats_result = $conn->query($stats_query);
$stats = $stats_result ? $stats_result->fetch_assoc() : [
    'total_tasks' => 0,
    'completed_tasks' => 0,
    'pending_tasks' => 0,
    'in_progress_tasks' => 0,
    'overdue_tasks' => 0,
    'due_today' => 0
];

// Get department-wise task distribution
$dept_stats_query = "
    SELECT d.name as department_name, COUNT(t.id) as task_count
    FROM intern_tasks t
    LEFT JOIN interns i ON t.intern_id = i.id
    LEFT JOIN departments d ON i.department_id = d.id
    WHERE d.name IS NOT NULL
    GROUP BY d.id, d.name
    ORDER BY task_count DESC
";

$dept_stats_result = $conn->query($dept_stats_query);
$dept_stats = [];
if ($dept_stats_result) {
    while ($row = $dept_stats_result->fetch_assoc()) {
        $dept_stats[] = $row;
    }
}

// Close connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Management - HR Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <!-- Include Sidebar -->
    <?php include 'sidebar_hr.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <div class="page-title">
                <h1><i class="fas fa-tasks"></i> Task Management</h1>
                <p>Manage and track tasks assigned to interns</p>
            </div>
            
            <div style="display: flex; align-items: center; gap: 10px;">
                <div class="user-profile">
                    <div class="user-avatar">
                        HR
                    </div>
                    <div class="user-info">
                        <h4>HR Manager</h4>
                        <span>Task Management</span>
                    </div>
                </div>
                <button onclick="window.location.href='hr_dashboard.php'" class="back-btn" title="Back to Dashboard">
                    <i class="fas fa-arrow-left"></i>
                </button>
            </div>
        </div>
        
        <!-- Message Display -->
        <?php if ($message): ?>
        <div class="message-box <?php echo $message_type; ?>">
            <i class="fas fa-<?php echo $message_type == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
            <button onclick="this.parentElement.style.display='none'">&times;</button>
        </div>
        <?php endif; ?>
        
        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon total">
                    <i class="fas fa-tasks"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['total_tasks']; ?></h3>
                    <p>Total Tasks</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon completed">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['completed_tasks']; ?></h3>
                    <p>Completed</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon pending">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['pending_tasks']; ?></h3>
                    <p>Pending</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon progress">
                    <i class="fas fa-spinner"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['in_progress_tasks']; ?></h3>
                    <p>In Progress</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon overdue">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['overdue_tasks']; ?></h3>
                    <p>Overdue</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon due">
                    <i class="fas fa-calendar-day"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['due_today']; ?></h3>
                    <p>Due Today</p>
                </div>
            </div>
        </div>
        
        <!-- Task Controls -->
        <div class="control-panel">
            <div class="search-box">
                <form method="GET" action="">
                    <input type="text" name="search" placeholder="Search tasks..." value="<?php echo htmlspecialchars($search_term); ?>">
                    <button type="submit"><i class="fas fa-search"></i></button>
                </form>
            </div>
            
            <div class="filters">
                <form method="GET" action="" class="filter-form">
                    <select name="status">
                        <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="in_progress" <?php echo $status_filter == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="overdue" <?php echo $status_filter == 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                    </select>
                    
                    <select name="priority">
                        <option value="all" <?php echo $priority_filter == 'all' ? 'selected' : ''; ?>>All Priority</option>
                        <option value="high" <?php echo $priority_filter == 'high' ? 'selected' : ''; ?>>High</option>
                        <option value="medium" <?php echo $priority_filter == 'medium' ? 'selected' : ''; ?>>Medium</option>
                        <option value="low" <?php echo $priority_filter == 'low' ? 'selected' : ''; ?>>Low</option>
                    </select>
                    
                    <select name="intern_id">
                        <option value="all" <?php echo $intern_filter == 'all' ? 'selected' : ''; ?>>All Interns</option>
                        <?php foreach ($interns as $intern): ?>
                        <option value="<?php echo $intern['id']; ?>" <?php echo $intern_filter == $intern['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($intern['first_name'] . ' ' . $intern['last_name'] . ' (' . $intern['intern_id'] . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <button type="submit" class="filter-btn"><i class="fas fa-filter"></i> Filter</button>
                    <button type="button" onclick="window.location.href='task_manage.php'" class="reset-btn">
                        <i class="fas fa-redo"></i> Reset
                    </button>
                </form>
            </div>
            
            <button class="add-btn" onclick="openAddModal()">
                <i class="fas fa-plus"></i> Add New Task
            </button>
        </div>
        
        <!-- Task Table -->
        <div class="dashboard-card">
            <div class="card-header">
                <h3>Task List</h3>
                <span>Showing <?php echo $tasks_result ? $tasks_result->num_rows : 0; ?> tasks</span>
            </div>
            <div class="table-container">
                <?php if ($tasks_result && $tasks_result->num_rows > 0): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Task Title</th>
                            <th>Intern</th>
                            <th>Department</th>
                            <th>Priority</th>
                            <th>Deadline</th>
                            <th>Status</th>
                            <th>Assigned By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($task = $tasks_result->fetch_assoc()): 
                            $is_overdue = strtotime($task['deadline']) < strtotime(date('Y-m-d')) && !in_array($task['status'], ['completed', 'overdue']);
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($task['title']); ?></strong>
                                <?php if ($task['description']): ?>
                                <div class="task-description"><?php echo htmlspecialchars(substr($task['description'], 0, 50)); ?><?php echo strlen($task['description']) > 50 ? '...' : ''; ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="user-cell">
                                    <div class="avatar-small"><?php echo strtoupper(substr($task['intern_first_name'], 0, 1) . substr($task['intern_last_name'], 0, 1)); ?></div>
                                    <div>
                                        <strong><?php echo htmlspecialchars($task['intern_first_name'] . ' ' . $task['intern_last_name']); ?></strong>
                                        <div class="small-text"><?php echo htmlspecialchars($task['intern_id']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($task['department_name'] ?? 'N/A'); ?></td>
                            <td>
                                <span class="priority-badge <?php echo $task['priority']; ?>">
                                    <i class="fas fa-<?php echo $task['priority'] == 'high' ? 'exclamation-circle' : ($task['priority'] == 'medium' ? 'exclamation' : 'arrow-down'); ?>"></i>
                                    <?php echo ucfirst($task['priority']); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo date('M d, Y', strtotime($task['deadline'])); ?>
                                <?php if ($is_overdue): ?>
                                <div class="overdue-text">Overdue!</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $task['status']; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($task['assigner_first_name'] . ' ' . $task['assigner_last_name']); ?>
                                <div class="small-text"><?php echo date('M d, Y', strtotime($task['assigned_date'])); ?></div>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button onclick="openViewModal(<?php echo htmlspecialchars(json_encode($task)); ?>)" class="action-btn view" title="View">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($task)); ?>)" class="action-btn edit" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button onclick="confirmDelete(<?php echo $task['id']; ?>)" class="action-btn delete" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-tasks"></i>
                    <h3>No tasks found</h3>
                    <p>No tasks match your current filters. Try adjusting your search criteria or add a new task.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Charts Section -->
        <div class="dashboard-grid">
            <!-- Department Distribution -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>Tasks by Department</h3>
                </div>
                <div class="chart-container">
                    <canvas id="departmentChart"></canvas>
                </div>
            </div>
            
            <!-- Status Distribution -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>Task Status Overview</h3>
                </div>
                <div class="chart-container">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Task Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add New Task</h2>
                <button onclick="closeAddModal()" class="close-btn">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Intern *</label>
                        <select name="intern_id" required>
                            <option value="">Select Intern</option>
                            <?php foreach ($interns as $intern): ?>
                            <option value="<?php echo $intern['id']; ?>">
                                <?php echo htmlspecialchars($intern['first_name'] . ' ' . $intern['last_name'] . ' (' . $intern['intern_id'] . ')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Task Title *</label>
                        <input type="text" name="title" required placeholder="Enter task title">
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" rows="3" placeholder="Enter task description"></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Deadline *</label>
                            <input type="date" name="deadline" required min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>Priority *</label>
                            <select name="priority" required>
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeAddModal()" class="cancel-btn">Cancel</button>
                    <button type="submit" name="add_task" class="submit-btn">Add Task</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- View/Edit Task Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Task Details</h2>
                <button onclick="closeEditModal()" class="close-btn">&times;</button>
            </div>
            <form method="POST" action="" id="editForm">
                <input type="hidden" name="task_id" id="editTaskId">
                <div class="modal-body">
                    <div class="task-info">
                        <div class="info-row">
                            <span class="label">Intern:</span>
                            <span id="viewIntern" class="value"></span>
                        </div>
                        <div class="info-row">
                            <span class="label">Department:</span>
                            <span id="viewDepartment" class="value"></span>
                        </div>
                        <div class="info-row">
                            <span class="label">Assigned By:</span>
                            <span id="viewAssigner" class="value"></span>
                        </div>
                        <div class="info-row">
                            <span class="label">Assigned Date:</span>
                            <span id="viewAssignedDate" class="value"></span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Task Title</label>
                        <input type="text" name="title" id="editTitle" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" id="editDescription" rows="3"></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Deadline</label>
                            <input type="date" name="deadline" id="editDeadline" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Priority</label>
                            <select name="priority" id="editPriority" required>
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" id="editStatus" required>
                                <option value="pending">Pending</option>
                                <option value="in_progress">In Progress</option>
                                <option value="completed">Completed</option>
                                <option value="overdue">Overdue</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Rating (1-5)</label>
                            <select name="rating" id="editRating">
                                <option value="">Not Rated</option>
                                <option value="1">1 - Poor</option>
                                <option value="2">2 - Fair</option>
                                <option value="3">3 - Good</option>
                                <option value="4">4 - Very Good</option>
                                <option value="5">5 - Excellent</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Feedback</label>
                        <textarea name="feedback" id="editFeedback" rows="2" placeholder="Enter feedback for the intern"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <div style="display: flex; justify-content: space-between; width: 100%;">
                        <button type="button" onclick="closeEditModal()" class="cancel-btn">Cancel</button>
                        <div>
                            <button type="submit" name="update_task" class="submit-btn">Save Changes</button>
                            <button type="button" onclick="deleteTask()" class="delete-btn">Delete Task</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Delete Confirmation Form -->
    <form method="POST" action="" id="deleteForm" style="display: none;">
        <input type="hidden" name="task_id" id="deleteTaskId">
        <input type="hidden" name="delete_task" value="1">
    </form>
    
    <style>
        /* Main Content Styles - Adjusted for Sidebar */
        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --secondary: #7e22ce;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
        }
        
        .main-content {
            margin-left: 260px;
            padding: 30px;
            min-height: 100vh;
            transition: all 0.3s ease;
        }
        
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
        }
        
        /* Top Bar */
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
            animation: slideDown 0.5s ease;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .page-title h1 {
            font-size: 32px;
            color: var(--dark);
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .page-title p {
            color: var(--gray);
            font-size: 14px;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            background: white;
            padding: 12px 20px;
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            margin-right: 15px;
            font-size: 18px;
        }
        
        .back-btn {
            background: var(--info);
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .back-btn:hover {
            background: #2563eb;
            transform: translateX(-3px);
        }
        
        /* Message Box */
        .message-box {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            animation: slideDown 0.3s ease;
        }
        
        .message-box.success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #10b981;
        }
        
        .message-box.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #ef4444;
        }
        
        .message-box i {
            margin-right: 10px;
            font-size: 18px;
        }
        
        .message-box button {
            margin-left: auto;
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: inherit;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 20px;
            color: white;
        }
        
        .stat-icon.total { background: linear-gradient(135deg, var(--primary) 0%, var(--info) 100%); }
        .stat-icon.completed { background: linear-gradient(135deg, var(--success) 0%, #34d399 100%); }
        .stat-icon.pending { background: linear-gradient(135deg, var(--warning) 0%, #fbbf24 100%); }
        .stat-icon.progress { background: linear-gradient(135deg, #8b5cf6 0%, #a78bfa 100%); }
        .stat-icon.overdue { background: linear-gradient(135deg, var(--danger) 0%, #f87171 100%); }
        .stat-icon.due { background: linear-gradient(135deg, #ec4899 0%, #f472b6 100%); }
        
        .stat-info h3 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--dark);
        }
        
        .stat-info p {
            color: var(--gray);
            font-size: 13px;
        }
        
        /* Control Panel */
        .control-panel {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }
        
        .search-box form {
            display: flex;
            align-items: center;
        }
        
        .search-box input {
            padding: 10px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px 0 0 8px;
            width: 250px;
            font-family: 'Poppins', sans-serif;
        }
        
        .search-box button {
            background: var(--primary);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 0 8px 8px 0;
            cursor: pointer;
            transition: background 0.3s ease;
        }
        
        .search-box button:hover {
            background: var(--primary-dark);
        }
        
        .filters .filter-form {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .filters select {
            padding: 10px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            background: white;
            color: var(--dark);
        }
        
        .filter-btn, .reset-btn, .add-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .filter-btn {
            background: var(--info);
            color: white;
        }
        
        .filter-btn:hover {
            background: #2563eb;
        }
        
        .reset-btn {
            background: var(--gray);
            color: white;
        }
        
        .reset-btn:hover {
            background: #475569;
        }
        
        .add-btn {
            background: var(--success);
            color: white;
        }
        
        .add-btn:hover {
            background: #059669;
            transform: translateY(-2px);
        }
        
        /* Task Table */
        .dashboard-card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .card-header h3 {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
        }
        
        .card-header span {
            color: var(--gray);
            font-size: 14px;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th {
            background: #f8fafc;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            font-size: 14px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .data-table td {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
            vertical-align: top;
        }
        
        .data-table tr:hover {
            background: #f8fafc;
        }
        
        .user-cell {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .avatar-small {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 12px;
        }
        
        .small-text {
            font-size: 12px;
            color: var(--gray);
        }
        
        .task-description {
            font-size: 12px;
            color: var(--gray);
            margin-top: 5px;
            line-height: 1.4;
        }
        
        .priority-badge {
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .priority-badge.high {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .priority-badge.medium {
            background: #fef3c7;
            color: #d97706;
        }
        
        .priority-badge.low {
            background: #dbeafe;
            color: #2563eb;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        
        .status-badge.pending { background: #fef3c7; color: #92400e; }
        .status-badge.in_progress { background: #dbeafe; color: #1e40af; }
        .status-badge.completed { background: #d1fae5; color: #065f46; }
        .status-badge.overdue { background: #fee2e2; color: #991b1b; }
        
        .overdue-text {
            font-size: 11px;
            color: var(--danger);
            font-weight: 500;
            margin-top: 3px;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .action-btn {
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .action-btn.view {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .action-btn.edit {
            background: #fef3c7;
            color: #92400e;
        }
        
        .action-btn.delete {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            opacity: 0.9;
        }
        
        .no-data {
            text-align: center;
            padding: 50px 20px;
        }
        
        .no-data i {
            font-size: 60px;
            color: #e2e8f0;
            margin-bottom: 20px;
        }
        
        .no-data h3 {
            color: var(--gray);
            margin-bottom: 10px;
        }
        
        .no-data p {
            color: var(--gray);
            font-size: 14px;
            max-width: 400px;
            margin: 0 auto;
        }
        
        /* Charts */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .chart-container {
            height: 250px;
            position: relative;
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }
        
        .modal-content {
            background: white;
            border-radius: 16px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            animation: slideUp 0.3s ease;
        }
        
        .modal-header {
            padding: 20px 25px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h2 {
            font-size: 20px;
            color: var(--dark);
        }
        
        .close-btn {
            background: none;
            border: none;
            font-size: 24px;
            color: var(--gray);
            cursor: pointer;
            transition: color 0.3s ease;
        }
        
        .close-btn:hover {
            color: var(--danger);
        }
        
        .modal-body {
            padding: 25px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
        }
        
        input, select, textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }
        
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        .task-info {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 10px;
        }
        
        .info-row:last-child {
            margin-bottom: 0;
        }
        
        .label {
            font-weight: 600;
            color: var(--dark);
            min-width: 120px;
        }
        
        .value {
            color: var(--gray);
        }
        
        .modal-footer {
            padding: 20px 25px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .cancel-btn, .submit-btn, .delete-btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .cancel-btn {
            background: #f1f5f9;
            color: var(--gray);
        }
        
        .cancel-btn:hover {
            background: #e2e8f0;
        }
        
        .submit-btn {
            background: var(--primary);
            color: white;
        }
        
        .submit-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        .delete-btn {
            background: var(--danger);
            color: white;
        }
        
        .delete-btn:hover {
            background: #dc2626;
            transform: translateY(-2px);
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideDown {
            from { 
                opacity: 0;
                transform: translateY(-20px);
            }
            to { 
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes slideUp {
            from { 
                opacity: 0;
                transform: translateY(20px);
            }
            to { 
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }
            
            .control-panel {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-box input {
                width: 100%;
            }
            
            .filters .filter-form {
                flex-direction: column;
                align-items: stretch;
            }
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .modal-content {
                width: 95%;
            }
        }
    </style>
    
    <script>
        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            // Department Chart
            const deptCtx = document.getElementById('departmentChart')?.getContext('2d');
            if (deptCtx) {
                const deptLabels = <?php echo json_encode(array_column($dept_stats, 'department_name')); ?>;
                const deptData = <?php echo json_encode(array_column($dept_stats, 'task_count')); ?>;
                
                new Chart(deptCtx, {
                    type: 'pie',
                    data: {
                        labels: deptLabels,
                        datasets: [{
                            data: deptData,
                            backgroundColor: [
                                '#4f46e5',
                                '#10b981',
                                '#f59e0b',
                                '#ef4444',
                                '#8b5cf6',
                                '#ec4899',
                                '#06b6d4'
                            ],
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right'
                            }
                        }
                    }
                });
            }
            
            // Status Chart
            const statusCtx = document.getElementById('statusChart')?.getContext('2d');
            if (statusCtx) {
                const statusData = [
                    <?php echo $stats['pending_tasks']; ?>,
                    <?php echo $stats['in_progress_tasks']; ?>,
                    <?php echo $stats['completed_tasks']; ?>,
                    <?php echo $stats['overdue_tasks']; ?>
                ];
                
                new Chart(statusCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Pending', 'In Progress', 'Completed', 'Overdue'],
                        datasets: [{
                            data: statusData,
                            backgroundColor: [
                                '#f59e0b',
                                '#3b82f6',
                                '#10b981',
                                '#ef4444'
                            ],
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }
                });
            }
        });
        
        // Modal Functions
        function openAddModal() {
            document.getElementById('addModal').style.display = 'flex';
        }
        
        function closeAddModal() {
            document.getElementById('addModal').style.display = 'none';
        }
        
        function openViewModal(task) {
            document.getElementById('modalTitle').textContent = 'Task Details';
            populateEditModal(task);
            document.getElementById('editModal').style.display = 'flex';
        }
        
        function openEditModal(task) {
            document.getElementById('modalTitle').textContent = 'Edit Task';
            populateEditModal(task);
            document.getElementById('editModal').style.display = 'flex';
        }
        
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }
        
        function populateEditModal(task) {
            document.getElementById('editTaskId').value = task.id;
            document.getElementById('editTitle').value = task.title;
            document.getElementById('editDescription').value = task.description || '';
            document.getElementById('editDeadline').value = task.deadline;
            document.getElementById('editPriority').value = task.priority;
            document.getElementById('editStatus').value = task.status;
            document.getElementById('editRating').value = task.rating || '';
            document.getElementById('editFeedback').value = task.feedback || '';
            
            // Populate view-only fields
            document.getElementById('viewIntern').textContent = 
                task.intern_first_name + ' ' + task.intern_last_name + ' (' + task.intern_id + ')';
            document.getElementById('viewDepartment').textContent = task.department_name || 'N/A';
            document.getElementById('viewAssigner').textContent = 
                task.assigner_first_name + ' ' + task.assigner_last_name;
            document.getElementById('viewAssignedDate').textContent = 
                new Date(task.assigned_date).toLocaleDateString('en-US', { 
                    year: 'numeric', 
                    month: 'short', 
                    day: 'numeric' 
                });
        }
        
        function deleteTask() {
            const taskId = document.getElementById('editTaskId').value;
            if (confirm('Are you sure you want to delete this task?')) {
                document.getElementById('deleteTaskId').value = taskId;
                document.getElementById('deleteForm').submit();
            }
        }
        
        function confirmDelete(taskId) {
            if (confirm('Are you sure you want to delete this task?')) {
                document.getElementById('deleteTaskId').value = taskId;
                document.getElementById('deleteForm').submit();
            }
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const addModal = document.getElementById('addModal');
            const editModal = document.getElementById('editModal');
            
            if (event.target == addModal) {
                closeAddModal();
            }
            if (event.target == editModal) {
                closeEditModal();
            }
        }
        
        // Set minimum date for deadline to today
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            const deadlineInput = document.querySelector('input[name="deadline"]');
            if (deadlineInput && !deadlineInput.value) {
                deadlineInput.min = today;
            }
        });
    </script>
</body>
</html>