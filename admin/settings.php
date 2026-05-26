<?php
// admin/settings.php
// --- NO OUTPUT ABOVE THIS LINE ---

require_once __DIR__ . '/../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Admin gate (redirect before output)
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$admin_id = (int)$_SESSION['user_id'];

// Helpers
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function valOrNull($v){ $v = trim((string)$v); return ($v === '') ? null : $v; }
function is_valid_year4($v){
    if ($v === '' || $v === null) return true;
    return preg_match('/^\d{4}$/', $v) === 1;
}

// Function to handle base64 image data from cropper
function handleCroppedImage($base64_data, $user_id) {
    if (empty($base64_data)) return null;
    
    // Check if it's a base64 string
    if (strpos($base64_data, 'data:image') === 0) {
        $uploadRoot = __DIR__ . '/../uploads/profiles';
        if (!is_dir($uploadRoot)) {
            @mkdir($uploadRoot, 0755, true);
        }
        $uploadDir = realpath($uploadRoot);
        
        if ($uploadDir === false) return null;
        
        // Extract the base64 data
        list($type, $data) = explode(';', $base64_data);
        list(, $data) = explode(',', $data);
        $data = base64_decode($data);
        
        // Determine file extension from mime type
        $mime = str_replace('data:', '', $type);
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp'
        ];
        
        $ext = $extensions[$mime] ?? 'jpg';
        
        // Validate image size (max 5MB)
        if (strlen($data) > 5 * 1024 * 1024) {
            return null;
        }
        
        // Generate filename
        $newName = 'profile_' . $user_id . '_' . time() . '_cropped.' . $ext;
        $destPath = $uploadDir . DIRECTORY_SEPARATOR . $newName;
        
        // Save the file
        if (file_put_contents($destPath, $data)) {
            return '../uploads/profiles/' . $newName;
        }
    }
    
    return null;
}

// CSRF
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf_token = $_SESSION['csrf_token'];

// Load current admin profile
$sql = "SELECT id, name, email, phone_number, date_of_birth, role, profile_pic,
               department, program_of_study, intake, country, gender,
               expected_graduation_year, created_at
        FROM users WHERE id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();

if (!$me) {
    $_SESSION['error'] = "Your account could not be found.";
    header('Location: ../login.php');
    exit();
}

$errors = [];
$success = "";

// Handle Profile Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_profile') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = "Invalid session token. Please try again.";
    } else {
        $name   = trim($_POST['name'] ?? '');
        $email  = trim($_POST['email'] ?? '');
        $phone  = trim($_POST['phone_number'] ?? '');
        $dob    = trim($_POST['date_of_birth'] ?? '');
        $dept   = valOrNull($_POST['department'] ?? '');
        $prog   = valOrNull($_POST['program_of_study'] ?? '');
        $intake = valOrNull($_POST['intake'] ?? '');
        $country= valOrNull($_POST['country'] ?? '');
        $gender = valOrNull($_POST['gender'] ?? '');
        $expyr  = valOrNull($_POST['expected_graduation_year'] ?? '');

        if ($name === '') $errors[] = "Name is required.";
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required.";
        if ($dob === '') $errors[] = "Date of Birth is required.";
        if ($phone === '') $errors[] = "Phone number is required.";
        if (!is_valid_year4($expyr)) $errors[] = "Expected graduation year must be 4 digits (e.g., 2029).";

        // Unique email except self
        $uq = $conn->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
        $uq->bind_param("si", $email, $admin_id);
        $uq->execute();
        if ($uq->get_result()->fetch_assoc()) {
            $errors[] = "Email is already used by another account.";
        }

        // Optional profile photo upload -> ../uploads/profiles
        $profilePath = null; // keep current if null

        // First check for cropped image data (from our cropper)
        if (!empty($_POST['cropped_image_data'])) {
            $profilePath = handleCroppedImage($_POST['cropped_image_data'], $admin_id);
            if ($profilePath === null) {
                $errors[] = "Failed to process cropped image.";
            }
        }
        // Fallback to regular file upload if no cropped data
        else if (!empty($_FILES['profile_pic']['name'])) {
            $uploadRoot = __DIR__ . '/../uploads/profiles';
            if (!is_dir($uploadRoot)) {
                @mkdir($uploadRoot, 0755, true);
            }
            $uploadDir = realpath($uploadRoot);

            if ($uploadDir !== false) {
                $fname  = $_FILES['profile_pic']['name'];
                $tmp    = $_FILES['profile_pic']['tmp_name'];
                $err    = $_FILES['profile_pic']['error'];
                $size   = (int)$_FILES['profile_pic']['size'];

                if ($err === UPLOAD_ERR_OK && is_uploaded_file($tmp)) {
                    $ext = null;
                    if (class_exists('finfo')) {
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mime  = $finfo->file($tmp);
                        $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
                        if (!isset($allowed[$mime])) {
                            $errors[] = "Profile image must be JPG, PNG, GIF, or WEBP.";
                        } else {
                            $ext = $allowed[$mime];
                        }
                    } else {
                        $extGuess = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                        if (in_array($extGuess, ['jpg','jpeg','png','gif','webp'], true)) {
                            $ext = $extGuess === 'jpeg' ? 'jpg' : $extGuess;
                        } else {
                            $errors[] = "Profile image must be JPG, PNG, GIF, or WEBP.";
                        }
                    }

                    if (!$errors) {
                        if ($size > 5*1024*1024) {
                            $errors[] = "Profile image must be 5MB or smaller.";
                        } else {
                            $safeBase = preg_replace('/[^A-Za-z0-9_\-]/','_', pathinfo($fname, PATHINFO_FILENAME));
                            $newName  = 'profile_'.$admin_id.'_'.time().'_'.$safeBase.'.'.$ext;
                            $destPath = $uploadDir . DIRECTORY_SEPARATOR . $newName;
                            if (move_uploaded_file($tmp, $destPath)) {
                                // Store relative path used across app
                                $profilePath = '../uploads/profiles/' . $newName;
                            } else {
                                $errors[] = "Failed to store uploaded image.";
                            }
                        }
                    }
                } else {
                    $errors[] = "Image upload error (code $err).";
                }
            } else {
                $errors[] = "Upload directory not available.";
            }
        }

        if (!$errors) {
            $sets = [
                "name = ?",
                "email = ?",
                "phone_number = ?",
                "date_of_birth = ?",
                "department = ?",
                "program_of_study = ?",
                "intake = ?",
                "country = ?",
                "gender = ?",
                "expected_graduation_year = ?"
            ];
            $types = "ssssssssss";
            $vals  = [$name,$email,$phone,$dob,$dept,$prog,$intake,$country,$gender,$expyr];

            if ($profilePath !== null) {
                $sets[] = "profile_pic = ?";
                $types .= "s";
                $vals[] = $profilePath;
            }

            $types .= "i";
            $vals[]  = $admin_id;

            $sqlU = "UPDATE users SET ".implode(", ", $sets)." WHERE id = ? LIMIT 1";
            $stU  = $conn->prepare($sqlU);
            $stU->bind_param($types, ...$vals);

            if ($stU->execute()) {
                $success = "Profile updated successfully.";
                // refresh $me for display
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $admin_id);
                $stmt->execute();
                $me = $stmt->get_result()->fetch_assoc();
            } else {
                $errors[] = "Update failed. Please try again.";
            }
        }
    }
}

// Handle Password Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = "Invalid session token. Please try again.";
    } else {
        $currentPw = trim($_POST['current_password'] ?? '');
        $newPw     = trim($_POST['new_password'] ?? '');
        $newPw2    = trim($_POST['confirm_password'] ?? '');

        if ($currentPw === '' || $newPw === '' || $newPw2 === '') {
            $errors[] = "All password fields are required.";
        } elseif ($newPw !== $newPw2) {
            $errors[] = "New password confirmation does not match.";
        } elseif (strlen($newPw) < 8) {
            $errors[] = "New password must be at least 8 characters.";
        } else {
            $pwStmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
            $pwStmt->bind_param("i", $admin_id);
            $pwStmt->execute();
            $hashRow = $pwStmt->get_result()->fetch_assoc();

            if (!$hashRow || !password_verify($currentPw, $hashRow['password'])) {
                $errors[] = "Current password is incorrect.";
            } else {
                $newHash = password_hash($newPw, PASSWORD_DEFAULT);
                $up = $conn->prepare("UPDATE users SET password = ? WHERE id = ? LIMIT 1");
                $up->bind_param("si", $newHash, $admin_id);
                if ($up->execute()) {
                    $success = "Password changed successfully.";
                } else {
                    $errors[] = "Failed to change password. Please try again.";
                }
            }
        }
    }
}

// From here, safe to output
require_once __DIR__ . '/header.php';

$profilePhoto = !empty($me['profile_pic']) ? $me['profile_pic'] : '../uploads/default-profile.jpg';
?>

<!-- Add Cropper CSS for this page only -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.css"/>

<style>
/* Profile Picture Styles (page-specific) */
.profile-pic-container {
    position: relative;
    width: 150px;
    height: 150px;
    margin: 0 auto 20px;
}
.profile-pic-container img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
    border: 4px solid #d1e7dd;
}
.profile-pic-upload {
    position: absolute;
    bottom: 6px;
    right: 6px;
    background: var(--primary);
    color: #fff;
    width: 42px;
    height: 42px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: var(--transition);
}
.profile-pic-upload:hover {
    background: var(--primary-dark);
    transform: scale(1.05);
}
.cropper-preview {
    width: 150px;
    height: 150px;
    overflow: hidden;
    border-radius: 50%;
    margin: 10px auto;
    border: 3px solid #eee;
}
.modal-header {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: #fff;
}
</style>

<main class="main-content container-fluid">
    <div class="row g-3">
        <div class="col-12 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <h2 class="mb-0">Settings</h2>
            <div class="d-flex align-items-center gap-3">
                <img src="<?= h($profilePhoto) ?>" onerror="this.src='../uploads/default-profile.jpg'"
                     class="rounded-circle" alt="Profile" style="width:56px;height:56px;object-fit:cover;border:2px solid #e9ecef;">
                <div>
                    <div class="fw-semibold"><?= h($me['name']) ?> <small class="text-muted">(#<?= (int)$me['id'] ?>)</small></div>
                    <div class="text-muted small"><?= h($me['email']) ?></div>
                </div>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="col-12">
                <div class="alert alert-danger mb-0">
                    <strong>Please fix the following:</strong>
                    <ul class="mb-0">
                        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="col-12">
                <div class="alert alert-success mb-0">
                    <?= h($success) ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Profile Settings -->
        <div class="col-12 col-lg-8">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fa-solid fa-user-gear me-2"></i>Profile</h5>
                </div>
                <div class="card-body">
                    <!-- Profile Picture Upload Section -->
                    <div class="profile-pic-container">
                        <img src="<?= h($profilePhoto) ?>" 
                             alt="Profile Picture" 
                             id="profile-pic-preview"
                             onerror="this.src='../uploads/default-profile.jpg'">
                        <label for="profile_pic" class="profile-pic-upload" title="Change photo">
                            <i class="fas fa-camera"></i>
                        </label>
                    </div>

                    <form method="post" enctype="multipart/form-data" class="row g-3" id="profileForm">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                        <input type="hidden" name="action" value="save_profile">
                        
                        <!-- Regular file input (hidden) for PHP backend -->
                        <input type="file" class="d-none" id="profile_pic" name="profile_pic" accept="image/*">
                        
                        <!-- Hidden field to store the final cropped image as file -->
                        <input type="hidden" name="cropped_image_data" id="cropped_image_data">

                        <div class="col-md-6">
                            <label class="form-label">Full Name</label>
                            <input type="text" class="form-control" name="name" value="<?= h($me['name']) ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" value="<?= h($me['email']) ?>" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Phone Number</label>
                            <input type="text" class="form-control" name="phone_number" value="<?= h($me['phone_number'] ?? '') ?>" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" class="form-control" name="date_of_birth" value="<?= h($me['date_of_birth'] ?? '') ?>" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Department</label>
                            <input type="text" class="form-control" name="department" value="<?= h($me['department'] ?? '') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Program of Study</label>
                            <input type="text" class="form-control" name="program_of_study" value="<?= h($me['program_of_study'] ?? '') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Intake</label>
                            <input type="text" class="form-control" name="intake" value="<?= h($me['intake'] ?? '') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Country</label>
                            <input type="text" class="form-control" name="country" value="<?= h($me['country'] ?? '') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Gender</label>
                            <input type="text" class="form-control" name="gender" value="<?= h($me['gender'] ?? '') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Expected Graduation Year</label>
                            <input type="text" class="form-control" name="expected_graduation_year" placeholder="e.g. 2029" value="<?= h($me['expected_graduation_year'] ?? '') ?>">
                        </div>

                        <div class="col-12 d-flex justify-content-end gap-2">
                            <button class="btn btn-primary" type="submit">
                                <i class="fa-solid fa-floppy-disk me-1"></i> Save Profile
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Password -->
        <div class="col-12 col-lg-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fa-solid fa-key me-2"></i>Change Password</h5>
                </div>
                <div class="card-body">
                    <form method="post" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                        <input type="hidden" name="action" value="change_password">

                        <div class="col-12">
                            <label class="form-label">Current Password</label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">New Password</label>
                            <input type="password" name="new_password" class="form-control" minlength="8" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" minlength="8" required>
                        </div>

                        <div class="col-12 d-grid">
                            <button class="btn btn-outline-primary" type="submit">
                                <i class="fa-solid fa-rotate me-1"></i> Update Password
                            </button>
                        </div>
                    </form>
                </div>
                <div class="card-footer bg-white">
                    <small class="text-muted">
                        Account created:
                        <?php if (!empty($me['created_at'])): ?>
                            <?= h(date('M j, Y g:i A', strtotime($me['created_at']))) ?>
                        <?php else: ?>
                            <em>Unknown</em>
                        <?php endif; ?>
                    </small>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Crop Modal -->
<div class="modal fade" id="profilePicModal" tabindex="-1" aria-labelledby="profilePicModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="profilePicModalLabel">Crop Profile Picture</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-8">
                        <div class="img-container">
                            <img id="image-to-crop" src="#" alt="Profile Picture" style="max-width:100%;">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="cropper-preview"></div>
                        <div class="d-grid gap-2 mt-3">
                            <button class="btn btn-light border" id="rotate-left" type="button">
                                <i class="fas fa-undo me-1"></i> Rotate Left
                            </button>
                            <button class="btn btn-light border" id="rotate-right" type="button">
                                <i class="fas fa-redo me-1"></i> Rotate Right
                            </button>
                            <button class="btn btn-primary" id="crop-btn" type="button">
                                <i class="fas fa-crop me-1"></i> Crop & Save
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Cropper JS for this page only -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.js"></script>

<script>
// Cropper functionality
let cropper;
const profilePicInput = document.getElementById('profile_pic');
const profilePicModal = new bootstrap.Modal(document.getElementById('profilePicModal'));
const imageToCrop = document.getElementById('image-to-crop');
const profilePicPreview = document.getElementById('profile-pic-preview');
const croppedImageData = document.getElementById('cropped_image_data');
const preview = document.querySelector('.cropper-preview');

// Profile picture upload trigger
profilePicInput?.addEventListener('change', function(){
    if (this.files && this.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e){
            imageToCrop.src = e.target.result;
            profilePicModal.show();
            
            // Initialize cropper when modal is shown
            document.getElementById('profilePicModal').addEventListener('shown.bs.modal', function initOnce(){
                if (cropper) cropper.destroy();
                
                cropper = new Cropper(imageToCrop, {
                    aspectRatio: 1,
                    viewMode: 1,
                    autoCropArea: 0.85,
                    responsive: true,
                    preview: preview,
                    guides: false,
                    center: false,
                    highlight: false,
                    cropBoxMovable: true,
                    cropBoxResizable: true,
                    toggleDragModeOnDblclick: false
                });
                
                // Remove event listener after first initialization
                document.getElementById('profilePicModal').removeEventListener('shown.bs.modal', initOnce);
            }, { once: true });
        };
        reader.readAsDataURL(this.files[0]);
    }
});

// Rotate buttons
document.getElementById('rotate-left')?.addEventListener('click', () => {
    if (cropper) cropper.rotate(-90);
});

document.getElementById('rotate-right')?.addEventListener('click', () => {
    if (cropper) cropper.rotate(90);
});

// Crop button
document.getElementById('crop-btn')?.addEventListener('click', () => {
    if (cropper) {
        const canvas = cropper.getCroppedCanvas({
            width: 600,
            height: 600,
            minWidth: 256,
            minHeight: 256,
            maxWidth: 1200,
            maxHeight: 1200,
            fillColor: '#fff',
            imageSmoothingEnabled: true,
            imageSmoothingQuality: 'high'
        });
        
        if (canvas) {
            const dataUrl = canvas.toDataURL('image/jpeg', 0.9);
            profilePicPreview.src = dataUrl;
            croppedImageData.value = dataUrl; // Store in hidden field for form submission
            profilePicModal.hide();
        }
    }
});

// Clean up cropper when modal is hidden
document.getElementById('profilePicModal')?.addEventListener('hidden.bs.modal', function(){
    if (cropper) {
        cropper.destroy();
        cropper = null;
    }
    // Clear the file input so same file can be selected again
    profilePicInput.value = '';
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
