<?php
/**
 * ATTENDANCE BULK UPLOAD PROCESSOR
 * HR Management Portal
 * 
 * Location: hr/dashboard/upload_attendance.php
 * Description: Processes Excel/CSV attendance uploads - ONLY PROCESSES ROWS WITH DATA
 * Linked with emp_system database
 */

session_start();

// Database configuration - Using your exact database structure
$conn = new mysqli('localhost', 'root', '', 'emp_system');

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// Check if HR is logged in
if (!isset($_SESSION['hr_logged_in']) || $_SESSION['hr_logged_in'] !== true) {
    header('Location: hr_dashboard.php');
    exit();
}

// Get HR user ID for audit logs
$hr_user_id = $_SESSION['user_id'] ?? 2; // Default to HR user ID 2

require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// ============================================
// HANDLE FILE UPLOAD
// ============================================
if (isset($_POST['upload_attendance'])) {

    if (!isset($_FILES['attendance_file']) || $_FILES['attendance_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['bulk_error_message'] = "Error uploading file. Please try again.";
        header("Location: attendance_manage.php");
        exit;
    }

    $fileName = $_FILES['attendance_file']['tmp_name'];
    $originalFileName = $_FILES['attendance_file']['name'];
    $extension = strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION));

    if (!in_array($extension, ['xlsx', 'xls', 'csv'])) {
        $_SESSION['bulk_error_message'] = "Invalid file type! Please upload .xlsx, .xls, or .csv files only.";
        header("Location: attendance_manage.php");
        exit;
    }

    try {
        $rawData = [];
        
        // Load the file
        if ($extension == 'csv') {
            $fileContent = file_get_contents($fileName);
            $fileContent = preg_replace('/\x{FEFF}/u', '', $fileContent);
            
            $tempFile = tmpfile();
            fwrite($tempFile, $fileContent);
            fseek($tempFile, 0);
            
            while (($row = fgetcsv($tempFile)) !== FALSE) {
                $rawData[] = $row;
            }
            fclose($tempFile);
        } else {
            $spreadsheet = IOFactory::load($fileName);
            $worksheet = $spreadsheet->getActiveSheet();
            $rawData = $worksheet->toArray();
        }

        // ============================================
        // FIND HEADER ROW - LOOK FOR "USER ID" IN FIRST COLUMN
        // ============================================
        
        $headerRowIndex = -1;
        
        foreach ($rawData as $index => $row) {
            if (isset($row[0]) && trim($row[0]) == 'USER ID') {
                $headerRowIndex = $index;
                break;
            }
        }
        
        if ($headerRowIndex === -1) {
            throw new Exception("Invalid template format. Header row with 'USER ID' not found.");
        }

        // Data starts after header row
        $dataStartRowIndex = $headerRowIndex + 1;

        $inserted = 0;
        $skipped = 0;
        $updated = 0;
        $errors = [];
        $warnings = [];
        $processed_records = [];

        $allowed_status = ['present', 'absent', 'half_day', 'holiday', 'week_off'];

        // Get attendance rules from database
        $rules_query = "SELECT rule_type, value FROM attendance_rules";
        $rules_result = $conn->query($rules_query);
        $rules = [];
        if ($rules_result) {
            while ($rule = $rules_result->fetch_assoc()) {
                $rules[$rule['rule_type']] = $rule['value'];
            }
        }
        
        $late_threshold = isset($rules['late_threshold']) ? (int)$rules['late_threshold'] : 15;
        $overtime_threshold = isset($rules['overtime_threshold']) ? (int)$rules['overtime_threshold'] : 480;

        $conn->begin_transaction();

        // ============================================
        // PROCESS DATA ROWS STARTING FROM HEADER ROW + 1
        // ============================================
        
        for ($i = $dataStartRowIndex; $i < count($rawData); $i++) {
            $row = $rawData[$i];
            $rowNumber = $i + 1; // Excel row number
            
            // Get the first 6 columns
            $user_id_code = isset($row[0]) ? trim($row[0]) : '';
            $date = isset($row[1]) ? trim($row[1]) : '';
            $check_in = isset($row[2]) && !empty(trim($row[2])) ? trim($row[2]) : null;
            $check_out = isset($row[3]) && !empty(trim($row[3])) ? trim($row[3]) : null;
            $status = isset($row[4]) ? strtolower(trim($row[4])) : '';
            $remarks = isset($row[5]) ? trim($row[5]) : '';

            // STOP PROCESSING if we hit a completely empty row
            if (empty($user_id_code) && empty($date) && empty($status) && 
                empty($check_in) && empty($check_out) && empty($remarks)) {
                break;
            }

            // Skip if this is actually the header row (safety check)
            if (strtoupper($user_id_code) == 'USER ID' || 
                strtoupper($user_id_code) == 'EMP ID' || 
                strtoupper($user_id_code) == 'EMPLOYEE ID') {
                continue;
            }

            // Validate required fields
            if (empty($user_id_code)) {
                $errors[] = "Row $rowNumber: User ID is missing";
                $skipped++;
                continue;
            }

            if (empty($date)) {
                $errors[] = "Row $rowNumber: Date is missing";
                $skipped++;
                continue;
            }

            if (empty($status)) {
                $errors[] = "Row $rowNumber: Status is missing";
                $skipped++;
                continue;
            }

            // ============================================
            // DATE VALIDATION AND FORMATTING
            // Handles multiple formats:
            // - YYYY-MM-DD (2026-02-12)
            // - DD-MM-YYYY (12-02-2026) 
            // - DD/MM/YYYY (12/02/2026)
            // - Excel numeric dates
            // - YYYY-MM-DD HH:MM:SS (2026-02-12 00:00:00)
            // ============================================
            
            // Remove any time component if present
            $date_only = $date;
            if (strpos($date, ' ') !== false) {
                $date_parts = explode(' ', $date);
                $date_only = $date_parts[0];
            }
            
            // Handle Excel numeric date
            if (is_numeric($date_only) && $date_only > 0) {
                try {
                    $unix = ($date_only - 25569) * 86400;
                    $date_only = gmdate('Y-m-d', $unix);
                } catch (Exception $e) {
                    // Keep original
                }
            }
            
            // Handle various date formats
            $formatted_date = null;
            
            // Format: YYYY-MM-DD (2026-02-12)
            if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $date_only)) {
                $date_parts = explode('-', $date_only);
                $formatted_date = sprintf('%04d-%02d-%02d', $date_parts[0], $date_parts[1], $date_parts[2]);
            }
            // Format: DD-MM-YYYY (12-02-2026)
            elseif (preg_match('/^\d{1,2}-\d{1,2}-\d{4}$/', $date_only)) {
                $date_parts = explode('-', $date_only);
                $formatted_date = sprintf('%04d-%02d-%02d', $date_parts[2], $date_parts[1], $date_parts[0]);
            }
            // Format: DD/MM/YYYY (12/02/2026)
            elseif (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $date_only)) {
                $date_parts = explode('/', $date_only);
                $formatted_date = sprintf('%04d-%02d-%02d', $date_parts[2], $date_parts[1], $date_parts[0]);
            }
            // Format: MM/DD/YYYY (02/12/2026) - US format
            elseif (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $date_only)) {
                $date_parts = explode('/', $date_only);
                $formatted_date = sprintf('%04d-%02d-%02d', $date_parts[2], $date_parts[0], $date_parts[1]);
            }
            // Format: DD.MM.YYYY (12.02.2026)
            elseif (preg_match('/^\d{1,2}\.\d{1,2}\.\d{4}$/', $date_only)) {
                $date_parts = explode('.', $date_only);
                $formatted_date = sprintf('%04d-%02d-%02d', $date_parts[2], $date_parts[1], $date_parts[0]);
            }
            
            // If we couldn't format the date, try using strtotime as fallback
            if (!$formatted_date) {
                $timestamp = strtotime($date_only);
                if ($timestamp !== false) {
                    $formatted_date = date('Y-m-d', $timestamp);
                }
            }
            
            // Final validation for YYYY-MM-DD format
            if (!$formatted_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $formatted_date)) {
                $errors[] = "Row $rowNumber: Invalid date format '$date'. Please use YYYY-MM-DD format";
                $skipped++;
                continue;
            }
            
            // Validate that it's a real date
            $date_parts = explode('-', $formatted_date);
            if (!checkdate((int)$date_parts[1], (int)$date_parts[2], (int)$date_parts[0])) {
                $errors[] = "Row $rowNumber: Invalid date '$formatted_date'. Please check day/month values.";
                $skipped++;
                continue;
            }
            
            // Check if date is not in future
            if (strtotime($formatted_date) > strtotime(date('Y-m-d'))) {
                $errors[] = "Row $rowNumber: Cannot mark attendance for future dates";
                $skipped++;
                continue;
            }
            
            $date_for_db = $formatted_date;

            // ============================================
            // TIME VALIDATION AND FORMATTING
            // ============================================
            
            // Format check-in time
            if ($check_in) {
                // Extract time from datetime string
                if (strpos($check_in, ' ') !== false) {
                    $time_parts = explode(' ', $check_in);
                    $check_in = end($time_parts);
                }
                
                // Handle time without seconds
                if (preg_match('/^\d{1,2}:\d{2}$/', $check_in)) {
                    $check_in = $check_in . ':00';
                }
                
                // Handle Excel time format
                if (is_numeric($check_in) && $check_in > 0 && $check_in < 1) {
                    try {
                        $check_in = gmdate('H:i:s', $check_in * 86400);
                    } catch (Exception $e) {
                        // Keep original
                    }
                }
                
                // Validate final format
                if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d):([0-5]\d)$/', $check_in)) {
                    $warnings[] = "Row $rowNumber: Invalid check-in time format '$check_in'. Setting to NULL.";
                    $check_in = null;
                }
            }
            
            // Format check-out time
            if ($check_out) {
                // Extract time from datetime string
                if (strpos($check_out, ' ') !== false) {
                    $time_parts = explode(' ', $check_out);
                    $check_out = end($time_parts);
                }
                
                // Handle time without seconds
                if (preg_match('/^\d{1,2}:\d{2}$/', $check_out)) {
                    $check_out = $check_out . ':00';
                }
                
                // Handle Excel time format
                if (is_numeric($check_out) && $check_out > 0 && $check_out < 1) {
                    try {
                        $check_out = gmdate('H:i:s', $check_out * 86400);
                    } catch (Exception $e) {
                        // Keep original
                    }
                }
                
                // Validate final format
                if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d):([0-5]\d)$/', $check_out)) {
                    $warnings[] = "Row $rowNumber: Invalid check-out time format '$check_out'. Setting to NULL.";
                    $check_out = null;
                }
            }

            // Validate status
            if (!in_array($status, $allowed_status)) {
                $errors[] = "Row $rowNumber: Invalid status '$status'. Allowed: " . implode(', ', $allowed_status);
                $skipped++;
                continue;
            }

            // ============================================
            // FIND USER IN DATABASE - Using your exact table structure
            // ============================================
            
            $user_id = null;
            $user_type = null;
            $shift_id = null;

            // Check employees table first
            $emp_sql = "SELECT user_id, shift_id FROM employees WHERE emp_id = ? AND employment_status = 'active'";
            $emp_stmt = $conn->prepare($emp_sql);
            if ($emp_stmt) {
                $emp_stmt->bind_param("s", $user_id_code);
                $emp_stmt->execute();
                $emp_result = $emp_stmt->get_result();
                if ($emp_row = $emp_result->fetch_assoc()) {
                    $user_id = $emp_row['user_id'];
                    $shift_id = $emp_row['shift_id'];
                    $user_type = 'employee';
                }
                $emp_stmt->close();
            }
            
            // If not found in employees, check interns table
            if (!$user_id) {
                $intern_sql = "SELECT user_id, shift_id FROM interns WHERE intern_id = ? AND internship_status = 'active'";
                $intern_stmt = $conn->prepare($intern_sql);
                if ($intern_stmt) {
                    $intern_stmt->bind_param("s", $user_id_code);
                    $intern_stmt->execute();
                    $intern_result = $intern_stmt->get_result();
                    if ($intern_row = $intern_result->fetch_assoc()) {
                        $user_id = $intern_row['user_id'];
                        $shift_id = $intern_row['shift_id'];
                        $user_type = 'intern';
                    }
                    $intern_stmt->close();
                }
            }
            
            // If not found in interns, check trainers table
            if (!$user_id) {
                $trainer_sql = "SELECT user_id FROM trainers WHERE trainer_id = ? AND employment_status = 'active'";
                $trainer_stmt = $conn->prepare($trainer_sql);
                if ($trainer_stmt) {
                    $trainer_stmt->bind_param("s", $user_id_code);
                    $trainer_stmt->execute();
                    $trainer_result = $trainer_stmt->get_result();
                    if ($trainer_row = $trainer_result->fetch_assoc()) {
                        $user_id = $trainer_row['user_id'];
                        $user_type = 'trainer';
                    }
                    $trainer_stmt->close();
                }
            }

            if (!$user_id || !$user_type) {
                $errors[] = "Row $rowNumber: User ID '$user_id_code' not found or inactive in database";
                $skipped++;
                continue;
            }

            // ============================================
            // CALCULATE LATE MINUTES AND OVERTIME
            // ============================================
            
            $late_minutes = 0;
            $overtime_minutes = 0;
            $early_departure_minutes = 0;
            
            if ($check_in && $shift_id && $user_type == 'employee') {
                // Get shift timings
                $shift_time_sql = "SELECT start_time, end_time FROM shifts WHERE id = ?";
                $shift_time_stmt = $conn->prepare($shift_time_sql);
                if ($shift_time_stmt) {
                    $shift_time_stmt->bind_param("i", $shift_id);
                    $shift_time_stmt->execute();
                    $shift_time_result = $shift_time_stmt->get_result();
                    
                    if ($shift_time_row = $shift_time_result->fetch_assoc()) {
                        $shift_start = $shift_time_row['start_time'];
                        $shift_end = $shift_time_row['end_time'];
                        
                        // Calculate late minutes
                        $check_in_time = strtotime($check_in);
                        $shift_start_time = strtotime($shift_start);
                        
                        if ($check_in_time > $shift_start_time) {
                            $late_minutes = floor(($check_in_time - $shift_start_time) / 60);
                            // Only count if exceeds threshold
                            if ($late_minutes < $late_threshold) {
                                $late_minutes = 0;
                            }
                        }
                        
                        // Calculate overtime and early departure
                        if ($check_out) {
                            $check_out_time = strtotime($check_out);
                            $shift_end_time = strtotime($shift_end);
                            
                            // Handle overnight shifts
                            if ($shift_end_time < $shift_start_time) {
                                $shift_end_time += 24 * 3600;
                                if ($check_out_time < $shift_start_time) {
                                    $check_out_time += 24 * 3600;
                                }
                            }
                            
                            if ($check_out_time > $shift_end_time) {
                                $overtime_minutes = floor(($check_out_time - $shift_end_time) / 60);
                                // Only count if exceeds threshold
                                if ($overtime_minutes < $overtime_threshold) {
                                    $overtime_minutes = 0;
                                }
                            }
                            
                            if ($check_out_time < $shift_end_time) {
                                $early_departure_minutes = floor(($shift_end_time - $check_out_time) / 60);
                            }
                        }
                    }
                    $shift_time_stmt->close();
                }
            }

            // ============================================
            // CHECK FOR DUPLICATE
            // ============================================
            
            $check_sql = "SELECT id FROM attendance WHERE user_id = ? AND user_type = ? AND date = ?";
            $check_stmt = $conn->prepare($check_sql);
            if ($check_stmt) {
                $check_stmt->bind_param("iss", $user_id, $user_type, $date_for_db);
                $check_stmt->execute();
                $duplicate_result = $check_stmt->get_result();
                $is_duplicate = $duplicate_result->num_rows > 0;
                
                if ($is_duplicate) {
                    $attendance_id = $duplicate_result->fetch_assoc()['id'];
                    $check_stmt->close();
                    
                    // Update existing attendance
                    $update_sql = "UPDATE attendance SET 
                                  check_in = ?, 
                                  check_out = ?, 
                                  status = ?, 
                                  shift_id = ?,
                                  late_minutes = ?,
                                  early_departure_minutes = ?,
                                  overtime_minutes = ?,
                                  remarks = ?, 
                                  updated_at = NOW()
                                  WHERE id = ?";
                    
                    $update_stmt = $conn->prepare($update_sql);
                    if ($update_stmt) {
                        $update_stmt->bind_param(
                            "sssiiissi",
                            $check_in,
                            $check_out,
                            $status,
                            $shift_id,
                            $late_minutes,
                            $early_departure_minutes,
                            $overtime_minutes,
                            $remarks,
                            $attendance_id
                        );

                        if ($update_stmt->execute()) {
                            $updated++;
                            $processed_records[] = "Updated: $user_id_code - $date_for_db - $status";
                            
                            // Add notification
                            $notification_sql = "INSERT INTO notifications (user_id, title, message, type, related_entity, related_entity_id, created_at) 
                                               VALUES (?, 'Attendance Updated (Bulk)', ?, 'info', 'attendance', ?, NOW())";
                            $notification_stmt = $conn->prepare($notification_sql);
                            if ($notification_stmt) {
                                $message_text = "Your attendance for $date_for_db has been updated via bulk upload. Status: " . ucfirst($status);
                                $notification_stmt->bind_param("isi", $user_id, $message_text, $attendance_id);
                                $notification_stmt->execute();
                                $notification_stmt->close();
                            }
                            
                            // Log to audit_logs
                            $audit_sql = "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, user_agent, created_at) 
                                         VALUES (?, 'bulk_update_attendance', 'attendance', ?, ?, ?, NOW())";
                            $audit_stmt = $conn->prepare($audit_sql);
                            if ($audit_stmt) {
                                $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                                $audit_stmt->bind_param("iiss", $hr_user_id, $attendance_id, $_SERVER['REMOTE_ADDR'], $user_agent);
                                $audit_stmt->execute();
                                $audit_stmt->close();
                            }
                        } else {
                            $errors[] = "Row $rowNumber: Database error - " . $conn->error;
                            $skipped++;
                        }
                        $update_stmt->close();
                    }
                } else {
                    $check_stmt->close();
                    
                    // Insert new attendance
                    $insert_sql = "INSERT INTO attendance 
                                  (user_id, user_type, date, shift_id, check_in, check_out, status, late_minutes, early_departure_minutes, overtime_minutes, remarks, created_at)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                    
                    $insert_stmt = $conn->prepare($insert_sql);
                    if ($insert_stmt) {
                        $insert_stmt->bind_param(
                            "ississsiiis",
                            $user_id,
                            $user_type,
                            $date_for_db,
                            $shift_id,
                            $check_in,
                            $check_out,
                            $status,
                            $late_minutes,
                            $early_departure_minutes,
                            $overtime_minutes,
                            $remarks
                        );

                        if ($insert_stmt->execute()) {
                            $attendance_id = $insert_stmt->insert_id;
                            $inserted++;
                            $processed_records[] = "Inserted: $user_id_code - $date_for_db - $status";
                            
                            // Add notification
                            $notification_sql = "INSERT INTO notifications (user_id, title, message, type, related_entity, related_entity_id, created_at) 
                                               VALUES (?, 'Attendance Marked (Bulk)', ?, 'success', 'attendance', ?, NOW())";
                            $notification_stmt = $conn->prepare($notification_sql);
                            if ($notification_stmt) {
                                $message_text = "Your attendance for $date_for_db has been marked via bulk upload. Status: " . ucfirst($status);
                                $notification_stmt->bind_param("isi", $user_id, $message_text, $attendance_id);
                                $notification_stmt->execute();
                                $notification_stmt->close();
                            }
                            
                            // Log to audit_logs
                            $audit_sql = "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, user_agent, created_at) 
                                         VALUES (?, 'bulk_create_attendance', 'attendance', ?, ?, ?, NOW())";
                            $audit_stmt = $conn->prepare($audit_sql);
                            if ($audit_stmt) {
                                $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                                $audit_stmt->bind_param("iiss", $hr_user_id, $attendance_id, $_SERVER['REMOTE_ADDR'], $user_agent);
                                $audit_stmt->execute();
                                $audit_stmt->close();
                            }
                        } else {
                            $errors[] = "Row $rowNumber: Database error - " . $conn->error;
                            $skipped++;
                        }
                        $insert_stmt->close();
                    }
                }
            }
        }

        // Update attendance_summary table for affected users
        if ($inserted > 0 || $updated > 0) {
            $update_summary_sql = "INSERT INTO attendance_summary 
                                  (user_id, user_type, summary_month, total_present, total_absent, total_half_days, total_late_days, total_overtime_minutes, created_at, updated_at)
                                  SELECT 
                                      a.user_id,
                                      a.user_type,
                                      DATE_FORMAT(a.date, '%Y-%m-01') as summary_month,
                                      SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as total_present,
                                      SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as total_absent,
                                      SUM(CASE WHEN a.status = 'half_day' THEN 1 ELSE 0 END) as total_half_days,
                                      SUM(CASE WHEN a.late_minutes > 0 THEN 1 ELSE 0 END) as total_late_days,
                                      SUM(a.overtime_minutes) as total_overtime_minutes,
                                      NOW(),
                                      NOW()
                                  FROM attendance a
                                  WHERE a.date >= DATE_FORMAT(NOW(), '%Y-%m-01')
                                  GROUP BY a.user_id, a.user_type, DATE_FORMAT(a.date, '%Y-%m-01')
                                  ON DUPLICATE KEY UPDATE
                                      total_present = VALUES(total_present),
                                      total_absent = VALUES(total_absent),
                                      total_half_days = VALUES(total_half_days),
                                      total_late_days = VALUES(total_late_days),
                                      total_overtime_minutes = VALUES(total_overtime_minutes),
                                      updated_at = NOW()";
            $conn->query($update_summary_sql);
        }

        $conn->commit();

        // ============================================
        // BUILD RESPONSE MESSAGE
        // ============================================
        
        if ($inserted > 0 || $updated > 0) {
            $_SESSION['bulk_success_message'] = "Successfully processed $inserted inserted and $updated updated attendance records. Skipped: $skipped";
            $_SESSION['bulk_processed_records'] = $processed_records;
            $_SESSION['bulk_errors'] = $errors;
            $_SESSION['bulk_warnings'] = $warnings;
        } else {
            $_SESSION['bulk_error_message'] = "Failed to process any records. Please check the errors below.";
            $_SESSION['bulk_errors'] = $errors;
            $_SESSION['bulk_warnings'] = $warnings;
        }

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['bulk_error_message'] = "Error processing file: " . $e->getMessage();
    }

    header("Location: attendance_manage.php");
    exit;
}

header("Location: attendance_manage.php");
exit;
?>