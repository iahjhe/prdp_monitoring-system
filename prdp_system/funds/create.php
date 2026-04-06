<!-- funds/create.php -->

<?php
session_start();
require_once '../config/database.php';

$db = new Database();
$conn = $db->getConnection();

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $conn->beginTransaction();
        
        // Get the year from input
        $year = $_POST['year'];
        
        // Check if year exists in years table
        $check = $conn->prepare("SELECT id FROM years WHERE year = ?");
        $check->execute([$year]);
        $existing_year = $check->fetch();
        
        if ($existing_year) {
            $year_id = $existing_year['id'];
        } else {
            // Insert the new year
            $stmt = $conn->prepare("INSERT INTO years (year, description, is_active) VALUES (?, ?, 1)");
            $stmt->execute([$year, 'Fiscal Year ' . $year]);
            $year_id = $conn->lastInsertId();
        }
        
        // Check if fund already exists for this year
        $check = $conn->prepare("SELECT id FROM funds WHERE year_id = ? AND fund_name = ?");
        $check->execute([$year_id, $_POST['fund_name']]);
        $existing = $check->fetch();
        
        // Generate fund code from fund name
        $fund_code = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $_POST['fund_name']));
        $fund_code = substr($fund_code, 0, 15);
        
        if ($existing) {
            // Update existing fund - using correct column names from schema
            $stmt = $conn->prepare("
                UPDATE funds 
                SET allotment = allotment + ?,
                    balance = balance + ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$_POST['total_allotment'], $_POST['total_allotment'], $existing['id']]);
            
            // Log the update
            $log_stmt = $conn->prepare("
                INSERT INTO fund_logs (fund_id, action, changes, created_at) 
                VALUES (?, 'updated', ?, CURRENT_TIMESTAMP)
            ");
            $log_stmt->execute([
                $existing['id'], 
                'Added allotment: ₱' . number_format($_POST['total_allotment'], 2)
            ]);
            
            $_SESSION['success'] = "Fund updated successfully. Added ₱" . number_format($_POST['total_allotment'], 2) . " to existing fund.";
        } else {
            // Create new fund - using correct column names from schema
            $stmt = $conn->prepare("
                INSERT INTO funds (
                    year_id, fund_code, fund_name, fund_source, 
                    allotment, obligated, disbursed, balance, status, created_at, updated_at
                ) VALUES (?, ?, ?, 'GOP', ?, 0, 0, ?, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            
            $stmt->execute([
                $year_id,
                $fund_code,
                $_POST['fund_name'],
                $_POST['total_allotment'],
                $_POST['total_allotment'] // balance initially equals allotment
            ]);
            
            $fund_id = $conn->lastInsertId();
            
            // Log the creation
            $log_stmt = $conn->prepare("
                INSERT INTO fund_logs (fund_id, action, changes, created_at) 
                VALUES (?, 'created', ?, CURRENT_TIMESTAMP)
            ");
            $log_stmt->execute([
                $fund_id, 
                'New fund created with allotment: ₱' . number_format($_POST['total_allotment'], 2)
            ]);
            
            $_SESSION['success'] = "New fund created successfully with ₱" . number_format($_POST['total_allotment'], 2) . " allotment.";
        }
        
        $conn->commit();
        header("Location: index.php");
        exit();
        
    } catch(Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Fund - PRDP Monitor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fc;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .card-header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            border-radius: 20px 20px 0 0 !important;
            padding: 30px;
            border: none;
        }
        .card-header h4 {
            font-weight: 700;
            margin-bottom: 5px;
            font-size: 1.8rem;
        }
        .card-header p {
            opacity: 0.8;
            margin: 0;
            font-size: 1rem;
        }
        .card-body {
            padding: 40px;
        }
        .form-label {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 10px;
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .form-control {
            border-radius: 12px;
            border: 2px solid #e9ecef;
            padding: 15px 20px;
            font-size: 1.1rem;
            transition: all 0.3s;
            background: #f8fafc;
        }
        .form-control:focus {
            border-color: #2a5298;
            box-shadow: 0 0 0 4px rgba(42,82,152,0.1);
            background: white;
        }
        .form-control::placeholder {
            color: #adb5bd;
            font-size: 1rem;
        }
        .input-group-text {
            background: #e9ecef;
            border: 2px solid #e9ecef;
            border-radius: 12px 0 0 12px;
            font-weight: 600;
            color: #2c3e50;
            padding: 15px 20px;
            font-size: 1.1rem;
        }
        .btn-primary {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            border: none;
            border-radius: 12px;
            padding: 15px 35px;
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s;
            width: 100%;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(42,82,152,0.4);
        }
        .btn-secondary {
            border-radius: 12px;
            padding: 15px 35px;
            font-weight: 600;
            background: #6c757d;
            border: none;
            font-size: 1.1rem;
            width: 100%;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
        .summary-box {
            background: linear-gradient(135deg, #f6f9fc 0%, #edf2f7 100%);
            border-radius: 15px;
            padding: 25px;
            margin-top: 30px;
            border-left: 5px solid #2a5298;
        }
        .summary-box h6 {
            color: #2c3e50;
            font-weight: 700;
            margin-bottom: 15px;
            font-size: 1rem;
        }
        .summary-box p {
            font-size: 1.2rem;
            color: #1e3c72;
            margin: 0;
            font-weight: 500;
        }
        .year-hint {
            background: #e9ecef;
            border-radius: 8px;
            padding: 8px 15px;
            margin-top: 10px;
            font-size: 0.9rem;
        }
        .year-hint i {
            color: #2a5298;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid py-5">
        <div class="row justify-content-center">
            <div class="col-md-7 col-lg-6">
                
                <!-- Alert Messages -->
                <?php if(isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show mb-4">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php 
                        echo $_SESSION['error'];
                        unset($_SESSION['error']);
                        ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-header text-center">
                        <h4><i class="fas fa-coins me-2"></i>Create New Fund</h4>
                        <p>Enter the fund details below</p>
                    </div>
                    
                    <div class="card-body">
                        <form method="POST" action="" id="fundForm">
                            
                            <!-- 1. Year Input (Text field) -->
                            <div class="mb-4">
                                <label class="form-label">
                                    <i class="fas fa-calendar-alt me-2 text-primary"></i>FISCAL YEAR
                                </label>
                                <input type="text" 
                                       class="form-control" 
                                       id="year" 
                                       name="year" 
                                       placeholder="e.g., 2025" 
                                       required
                                       pattern="[0-9]{4}"
                                       maxlength="4"
                                       title="Please enter a valid 4-digit year">
                                <div class="year-hint">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Enter the fiscal year (e.g., 2025, 2026, etc.)
                                </div>
                            </div>
                            
                            <!-- 2. Fund Name Input -->
                            <div class="mb-4">
                                <label class="form-label">
                                    <i class="fas fa-tag me-2 text-success"></i>FUND NAME
                                </label>
                                <input type="text" 
                                       class="form-control" 
                                       id="fund_name" 
                                       name="fund_name" 
                                       placeholder="e.g., PRDP Scale-Up 2025, World Bank Grant" 
                                       required>
                                <div class="year-hint">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Enter a descriptive name for this fund
                                </div>
                            </div>
                            
                            <!-- 3. Allotment Amount Input -->
                            <div class="mb-4">
                                <label class="form-label">
                                    <i class="fas fa-money-bill-wave me-2 text-warning"></i>ALLOTMENT AMOUNT (₱)
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" 
                                           step="0.01" 
                                           class="form-control" 
                                           id="total_allotment" 
                                           name="total_allotment" 
                                           placeholder="0.00" 
                                           required>
                                </div>
                                <div class="year-hint">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Enter the total allotment amount
                                </div>
                            </div>
                            
                            <!-- Live Summary -->
                            <div class="summary-box" id="summaryBox" style="display: none;">
                                <h6><i class="fas fa-file-invoice me-2"></i>SUMMARY</h6>
                                <p id="summaryText"></p>
                            </div>
                            
                            <!-- Form Actions -->
                            <div class="row mt-5">
                                <div class="col-md-6 mb-3 mb-md-0">
                                    <a href="index.php" class="btn btn-secondary">
                                        <i class="fas fa-times me-2"></i>Cancel
                                    </a>
                                </div>
                                <div class="col-md-6">
                                    <button type="submit" class="btn btn-primary" id="submitBtn">
                                        <i class="fas fa-save me-2"></i>Create Fund
                                    </button>
                                </div>
                            </div>
                            
                        </form>
                    </div>
                </div>
                
                <!-- Quick Tips Card -->
                <div class="card mt-4 bg-light border-0">
                    <div class="card-body p-4">
                        <h6 class="mb-3"><i class="fas fa-lightbulb text-warning me-2"></i>Quick Tips</h6>
                        <div class="row">
                            <div class="col-md-4">
                                <small class="text-muted d-block mb-2">
                                    <i class="fas fa-check-circle text-success me-1"></i>
                                    Year must be 4 digits
                                </small>
                            </div>
                            <div class="col-md-4">
                                <small class="text-muted d-block mb-2">
                                    <i class="fas fa-check-circle text-success me-1"></i>
                                    Fund name should be unique per year
                                </small>
                            </div>
                            <div class="col-md-4">
                                <small class="text-muted d-block mb-2">
                                    <i class="fas fa-check-circle text-success me-1"></i>
                                    Amount must be greater than 0
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
                
            </div>
        </div>
    </div>
    
    <?php include '../includes/footer.php'; ?>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        $(document).ready(function() {
            
            // Live summary update
            function updateSummary() {
                const year = $('#year').val();
                const fundName = $('#fund_name').val();
                const amount = $('#total_allotment').val();
                
                if (year && fundName && amount && parseFloat(amount) > 0) {
                    // Validate year format
                    if (!/^\d{4}$/.test(year)) {
                        $('#summaryText').html(`
                            <span class="text-danger">
                                <i class="fas fa-exclamation-triangle"></i> 
                                Please enter a valid 4-digit year
                            </span>
                        `);
                        $('#summaryBox').fadeIn();
                        return;
                    }
                    
                    const formattedAmount = new Intl.NumberFormat('en-PH', {
                        style: 'currency',
                        currency: 'PHP',
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }).format(amount);
                    
                    $('#summaryText').html(`
                        <strong>${fundName}</strong><br>
                        Fiscal Year: ${year}<br>
                        Allotment: ${formattedAmount}
                    `);
                    
                    $('#summaryBox').fadeIn();
                } else {
                    $('#summaryBox').fadeOut();
                }
            }
            
            $('#year, #fund_name, #total_allotment').on('keyup change input', updateSummary);
            
            // Year input validation
            $('#year').on('input', function() {
                let value = $(this).val();
                // Remove non-numeric characters
                value = value.replace(/[^0-9]/g, '');
                // Limit to 4 digits
                if (value.length > 4) {
                    value = value.slice(0, 4);
                }
                $(this).val(value);
            });
            
            // Prevent negative values in amount field
            $('#total_allotment').on('keydown', function(e) {
                if (e.key === '-' || e.key === 'e') {
                    e.preventDefault();
                }
            });
            
            // Form validation
            $('#fundForm').on('submit', function(e) {
                const year = $('#year').val();
                const fundName = $('#fund_name').val().trim();
                const amount = parseFloat($('#total_allotment').val());
                
                let errors = [];
                
                // Validate year
                if (!year) {
                    errors.push('Please enter a fiscal year');
                } else if (!/^\d{4}$/.test(year)) {
                    errors.push('Year must be a 4-digit number (e.g., 2025)');
                }
                
                // Validate fund name
                if (!fundName) {
                    errors.push('Please enter a fund name');
                }
                
                // Validate amount
                if (!amount || amount <= 0) {
                    errors.push('Please enter a valid amount greater than 0');
                }
                
                if (errors.length > 0) {
                    e.preventDefault();
                    let errorMessage = 'Please fix the following:\n\n';
                    errors.forEach(error => {
                        errorMessage += '• ' + error + '\n';
                    });
                    alert(errorMessage);
                }
            });
            
            // Trigger initial update if fields are pre-filled
            updateSummary();
            
        });
    </script>
</body>
</html>