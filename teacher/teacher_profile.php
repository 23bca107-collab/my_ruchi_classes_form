<?php
session_start();

require_once __DIR__ . '/teacher_auth.php';

requireTeacherAuth();
$teacher = getTeacherInfo();

$isUpdateMode = !empty($teacher['profile_completed']);
$subjectOptions = [
    'Mathematics',
    'Science',
    'English',
    'Social Studies',
    'Computer Science',
    'Physics',
    'Chemistry',
    'Biology',
    'History',
    'Geography',
    'Other',
];

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$currentPhoto = trim((string)($teacher['photo'] ?? ''));
$photoPrimary = $currentPhoto;
$photoFallback = $currentPhoto !== '' ? '../' . ltrim($currentPhoto, './') : '';
$pageTitle = $isUpdateMode ? 'Update Your Profile' : 'Complete Your Profile';
$pageSubtitle = $isUpdateMode
    ? 'Your saved profile details are loaded below. Update anything and save again.'
    : 'Fill in your details to complete your teacher account setup.';
$submitLabel = $isUpdateMode ? 'Update Profile' : 'Save Profile';
$photoButtonLabel = $isUpdateMode ? 'Choose New Profile Photo' : 'Choose Profile Photo';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $isUpdateMode ? 'Update Teacher Profile' : 'Teacher Profile'; ?> | Ruchi Classes</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root {
      --primary: #2563eb;
      --primary-dark: #1d4ed8;
      --secondary: #eff6ff;
      --accent: #f59e0b;
      --success: #10b981;
      --danger: #ef4444;
      --text-primary: #1e293b;
      --text-secondary: #475569;
      --border: #dbeafe;
      --bg-card: rgba(255, 255, 255, 0.96);
      --shadow: 0 24px 60px rgba(15, 23, 42, 0.16);
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    body {
      min-height: 100vh;
      background:
        radial-gradient(circle at top left, rgba(37, 99, 235, 0.16), transparent 38%),
        radial-gradient(circle at bottom right, rgba(245, 158, 11, 0.12), transparent 35%),
        linear-gradient(135deg, #dbeafe 0%, #eff6ff 45%, #ffffff 100%);
      color: var(--text-primary);
      padding: 24px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .container {
      display: grid;
      grid-template-columns: 360px minmax(0, 1fr);
      width: min(1120px, 100%);
      background: var(--bg-card);
      border: 1px solid rgba(255, 255, 255, 0.7);
      border-radius: 28px;
      overflow: hidden;
      box-shadow: var(--shadow);
      backdrop-filter: blur(18px);
    }

    .brand-section {
      background: linear-gradient(160deg, #2563eb 0%, #1d4ed8 60%, #0f172a 100%);
      color: #fff;
      padding: 42px 34px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      gap: 28px;
    }

    .brand-header {
      display: flex;
      align-items: center;
      gap: 16px;
    }

    .logo {
      width: 74px;
      height: 74px;
      border-radius: 22px;
      background: rgba(255, 255, 255, 0.16);
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.14);
      backdrop-filter: blur(6px);
      flex-shrink: 0;
    }

    .logo i {
      font-size: 30px;
    }

    .brand-copy h1 {
      font-size: 30px;
      line-height: 1.1;
      margin-bottom: 8px;
    }

    .brand-copy p {
      color: rgba(255, 255, 255, 0.82);
      line-height: 1.6;
      font-size: 15px;
    }

    .profile-preview {
      background: rgba(255, 255, 255, 0.12);
      border: 1px solid rgba(255, 255, 255, 0.16);
      border-radius: 24px;
      padding: 24px;
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      gap: 14px;
    }

    .profile-preview-avatar {
      width: 110px;
      height: 110px;
      border-radius: 28px;
      object-fit: cover;
      border: 3px solid rgba(255, 255, 255, 0.78);
      box-shadow: 0 16px 30px rgba(15, 23, 42, 0.24);
      background: rgba(255, 255, 255, 0.16);
    }

    .profile-preview-fallback {
      width: 110px;
      height: 110px;
      border-radius: 28px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 36px;
      font-weight: 800;
      background: rgba(255, 255, 255, 0.18);
      border: 3px solid rgba(255, 255, 255, 0.78);
      box-shadow: 0 16px 30px rgba(15, 23, 42, 0.24);
    }

    .profile-preview h2 {
      font-size: 24px;
      line-height: 1.2;
    }

    .profile-preview p {
      color: rgba(255, 255, 255, 0.86);
      font-size: 14px;
      line-height: 1.6;
    }

    .status-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 14px;
      border-radius: 999px;
      font-size: 13px;
      font-weight: 700;
      background: rgba(16, 185, 129, 0.16);
      color: #d1fae5;
    }

    .form-section {
      padding: 42px 38px;
    }

    .form-header {
      margin-bottom: 28px;
    }

    .form-header h2 {
      font-size: 30px;
      margin-bottom: 10px;
    }

    .form-header p {
      color: var(--text-secondary);
      line-height: 1.6;
      max-width: 620px;
    }

    .top-actions {
      display: flex;
      justify-content: flex-end;
      margin-bottom: 18px;
    }

    .back-link {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      text-decoration: none;
      color: var(--primary);
      font-weight: 700;
      font-size: 14px;
    }

    .form-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 18px;
    }

    .form-group {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .form-group.full-width {
      grid-column: 1 / -1;
    }

    label {
      font-size: 14px;
      font-weight: 700;
      color: var(--text-primary);
    }

    input,
    textarea,
    select {
      width: 100%;
      padding: 14px 16px;
      border: 1px solid var(--border);
      border-radius: 16px;
      font-size: 15px;
      color: var(--text-primary);
      background: #fff;
      transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
    }

    input:focus,
    textarea:focus,
    select:focus {
      outline: none;
      border-color: rgba(37, 99, 235, 0.7);
      box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
      transform: translateY(-1px);
    }

    textarea {
      min-height: 120px;
      resize: vertical;
    }

    .file-input-wrapper {
      position: relative;
      overflow: hidden;
    }

    .file-input-wrapper input[type=file] {
      position: absolute;
      inset: 0;
      opacity: 0;
      cursor: pointer;
    }

    .file-input-button {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      padding: 16px;
      border: 1px dashed rgba(37, 99, 235, 0.4);
      border-radius: 18px;
      background: linear-gradient(135deg, rgba(37, 99, 235, 0.06), rgba(59, 130, 246, 0.03));
      color: var(--primary);
      font-weight: 700;
      min-height: 58px;
      text-align: center;
    }

    .photo-hint {
      margin-top: 8px;
      font-size: 13px;
      color: var(--text-secondary);
    }

    .current-photo-strip {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 12px 14px;
      border-radius: 18px;
      background: var(--secondary);
      border: 1px solid rgba(37, 99, 235, 0.12);
      margin-top: 10px;
    }

    .current-photo-strip img {
      width: 54px;
      height: 54px;
      border-radius: 16px;
      object-fit: cover;
      border: 2px solid rgba(37, 99, 235, 0.18);
      flex-shrink: 0;
    }

    .current-photo-strip strong {
      display: block;
      margin-bottom: 4px;
      font-size: 14px;
    }

    .current-photo-strip span {
      color: var(--text-secondary);
      font-size: 13px;
      line-height: 1.5;
    }

    .submit-row {
      margin-top: 26px;
      display: flex;
      gap: 14px;
      align-items: center;
      flex-wrap: wrap;
    }

    .submit-btn,
    .secondary-btn {
      border: none;
      cursor: pointer;
      border-radius: 18px;
      padding: 15px 22px;
      font-size: 15px;
      font-weight: 800;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      text-decoration: none;
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .submit-btn {
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
      color: #fff;
      box-shadow: 0 18px 28px rgba(37, 99, 235, 0.18);
      min-width: 220px;
    }

    .secondary-btn {
      background: #fff;
      color: var(--text-primary);
      border: 1px solid rgba(148, 163, 184, 0.28);
    }

    .submit-btn:hover,
    .secondary-btn:hover {
      transform: translateY(-1px);
    }

    .form-footer {
      margin-top: 18px;
      color: var(--text-secondary);
      font-size: 13px;
      line-height: 1.6;
    }

    @media (max-width: 960px) {
      body {
        padding: 16px;
      }

      .container {
        grid-template-columns: 1fr;
      }

      .brand-section,
      .form-section {
        padding: 28px 22px;
      }
    }

    @media (max-width: 640px) {
      .form-grid {
        grid-template-columns: 1fr;
      }

      .submit-row {
        flex-direction: column;
        align-items: stretch;
      }

      .submit-btn,
      .secondary-btn {
        width: 100%;
      }

      .profile-preview {
        padding: 20px;
      }
    }
  </style>
</head>
<body>
  <div class="container">
    <section class="brand-section">
      <div class="brand-header">
        <div class="logo">
          <i class="fas fa-chalkboard-teacher"></i>
        </div>
        <div class="brand-copy">
          <h1>Ruchi Classes</h1>
          <p>Teacher profile panel with your saved database details and quick updates.</p>
        </div>
      </div>

      <div class="profile-preview">
        <?php if ($currentPhoto !== ''): ?>
          <img
            src="<?php echo h($photoPrimary); ?>"
            alt="<?php echo h(trim(($teacher['first_name'] ?? '') . ' ' . ($teacher['last_name'] ?? ''))); ?>"
            class="profile-preview-avatar"
            onerror="this.src='<?php echo h($photoFallback); ?>'; this.onerror=null;"
          >
        <?php else: ?>
          <div class="profile-preview-fallback">
            <?php
              $initials = strtoupper(substr((string)($teacher['first_name'] ?? 'T'), 0, 1) . substr((string)($teacher['last_name'] ?? ''), 0, 1));
              echo h($initials !== '' ? $initials : 'T');
            ?>
          </div>
        <?php endif; ?>

        <div class="status-pill">
          <i class="fas fa-database"></i>
          <?php echo $isUpdateMode ? 'Saved Profile Loaded' : 'Complete Your Profile'; ?>
        </div>

        <h2><?php echo h(trim(($teacher['first_name'] ?? '') . ' ' . ($teacher['last_name'] ?? '')) ?: 'Teacher'); ?></h2>
        <p>
          <?php echo h(trim((string)($teacher['subject'] ?? '')) !== '' ? ($teacher['subject'] . ' Teacher') : 'Teacher'); ?><br>
          <?php echo h((string)($teacher['email'] ?? '')); ?>
        </p>
      </div>
    </section>

    <section class="form-section">
      <div class="top-actions">
        <a href="teacher_dashboard.php" class="back-link">
          <i class="fas fa-arrow-left"></i>
          Back to Dashboard
        </a>
      </div>

      <div class="form-header">
        <h2><?php echo h($pageTitle); ?></h2>
        <p><?php echo h($pageSubtitle); ?></p>
      </div>

      <form action="teacher_profile_save.php" method="POST" enctype="multipart/form-data">
        <div class="form-grid">
          <div class="form-group">
            <label for="first_name">First Name</label>
            <input type="text" id="first_name" name="first_name" value="<?php echo h($teacher['first_name'] ?? ''); ?>" placeholder="Enter your first name" required>
          </div>

          <div class="form-group">
            <label for="last_name">Last Name</label>
            <input type="text" id="last_name" name="last_name" value="<?php echo h($teacher['last_name'] ?? ''); ?>" placeholder="Enter your last name" required>
          </div>

          <div class="form-group">
            <label for="mobile">Mobile Number</label>
            <input type="text" id="mobile" name="mobile" value="<?php echo h($teacher['mobile'] ?? ''); ?>" placeholder="Enter your mobile number" required>
          </div>

          <div class="form-group">
            <label for="subject">Subject You Teach</label>
            <select id="subject" name="subject" required>
              <option value="" disabled <?php echo trim((string)($teacher['subject'] ?? '')) === '' ? 'selected' : ''; ?>>Select your subject</option>
              <?php foreach ($subjectOptions as $subjectOption): ?>
                <option value="<?php echo h($subjectOption); ?>" <?php echo (string)($teacher['subject'] ?? '') === $subjectOption ? 'selected' : ''; ?>>
                  <?php echo h($subjectOption); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group full-width">
            <label for="address">Address</label>
            <textarea id="address" name="address" rows="4" placeholder="Enter your complete address" required><?php echo h($teacher['address'] ?? ''); ?></textarea>
          </div>

          <div class="form-group full-width">
            <label for="photo">Profile Photo</label>
            <div class="file-input-wrapper">
              <div class="file-input-button" id="fileInputButton">
                <i class="fas fa-cloud-upload-alt"></i>
                <span id="fileInputText"><?php echo h($photoButtonLabel); ?></span>
              </div>
              <input type="file" id="photo" name="photo" accept="image/*" <?php echo $currentPhoto === '' ? 'required' : ''; ?>>
            </div>
            <div class="photo-hint">JPG, PNG, GIF, or WEBP. <?php echo $currentPhoto === '' ? 'Profile photo required.' : 'Leave empty if you want to keep the current photo.'; ?></div>

            <?php if ($currentPhoto !== ''): ?>
              <div class="current-photo-strip">
                <img src="<?php echo h($photoPrimary); ?>" alt="Current Profile Photo" onerror="this.src='<?php echo h($photoFallback); ?>'; this.onerror=null;">
                <div>
                  <strong>Current profile photo</strong>
                  <span>A new photo select karoge to ye replace ho jayegi.</span>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="submit-row">
          <button type="submit" class="submit-btn">
            <i class="fas fa-user-check"></i>
            <?php echo h($submitLabel); ?>
          </button>
          <a href="teacher_dashboard.php" class="secondary-btn">
            <i class="fas fa-times"></i>
            Cancel
          </a>
        </div>

        <div class="form-footer">
          Your saved teacher profile details appear in the header profile popup across teacher pages.
        </div>
      </form>
    </section>
  </div>

  <script>
    const photoInput = document.getElementById('photo');
    const fileInputText = document.getElementById('fileInputText');

    photoInput.addEventListener('change', function(event) {
      const selectedFile = event.target.files[0];
      fileInputText.textContent = selectedFile ? selectedFile.name : '<?php echo h($photoButtonLabel); ?>';
    });
  </script>
</body>
</html>
