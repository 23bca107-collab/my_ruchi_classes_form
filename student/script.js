// Validation patterns and rules
const VALIDATION_RULES = {
    email: {
        pattern: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
        message: 'Enter a valid email address'
    },
    mobile: {
        pattern: /^[0-9]{10}$/,
        message: 'Enter a valid 10-digit mobile number'
    },
    pincode: {
        pattern: /^[0-9]{6}$/,
        message: 'Enter a valid 6-digit pincode'
    },
    marks: {
        min: 0,
        max: 100,
        message: 'Enter valid marks between 0-100'
    }
};

// Photo validation
const PHOTO_CONFIG = {
    allowedTypes: ['image/jpeg', 'image/jpg', 'image/png'],
    maxSize: 2 * 1024 * 1024 // 2MB
};

// Initialize form on page load
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('admissionForm');
    initializeForm(form);
});

/**
 * Initialize all form functionality
 */
function initializeForm(form) {
    setupDateRestriction();
    setupPhotoPreview();
    setupMobileNumberInput();
    setupPincodeInput();
    setupMarksInput();
    setupHomeButton();
    setupFormSubmission(form);
    preventFormResubmission();
    handleBackButton();
}

/**
 * Set max date for DOB (minimum age 5 years)
 */
function setupDateRestriction() {
    const dobInput = document.getElementById('dob');
    const today = new Date();
    const maxDate = new Date(today.getFullYear() - 5, today.getMonth(), today.getDate());
    dobInput.max = maxDate.toISOString().split('T')[0];
}

/**
 * Handle photo file selection and preview
 */
function setupPhotoPreview() {
    const photoInput = document.getElementById('photo');
    const previewImg = document.getElementById('previewImg');
    const previewText = document.getElementById('previewText');

    photoInput.addEventListener('change', function() {
        const file = this.files[0];
        if (file) {
            // Validate file type
            if (!PHOTO_CONFIG.allowedTypes.includes(file.type)) {
                showFieldError('photo', 'Please select JPG, JPEG or PNG only');
                this.value = '';
                return;
            }

            // Validate file size
            if (file.size > PHOTO_CONFIG.maxSize) {
                showFieldError('photo', 'Image must be smaller than 2MB');
                this.value = '';
                return;
            }

            // Display preview
            const reader = new FileReader();
            previewText.style.display = 'none';
            previewImg.style.display = 'block';
            reader.addEventListener('load', function() {
                previewImg.setAttribute('src', this.result);
            });
            reader.readAsDataURL(file);
            clearFieldError('photo');
        } else {
            previewText.style.display = 'block';
            previewImg.style.display = 'none';
        }
    });
}

/**
 * Setup real-time validation for mobile number fields
 */
function setupMobileNumberInput() {
    const mobileFields = ['parent_mobile', 'personal_mobile', 'whatsapp'];
    mobileFields.forEach(field => {
        const input = document.getElementById(field);
        input.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
            if (this.value.length > 10) {
                this.value = this.value.slice(0, 10);
            }
        });
    });
}

/**
 * Setup real-time validation for pincode field
 */
function setupPincodeInput() {
    const pincodeInput = document.getElementById('pincode');
    pincodeInput.addEventListener('input', function() {
        this.value = this.value.replace(/[^0-9]/g, '');
        if (this.value.length > 6) {
            this.value = this.value.slice(0, 6);
        }
    });
}

/**
 * Setup real-time validation for marks field
 */
function setupMarksInput() {
    const marksInput = document.getElementById('previous_marks');
    marksInput.addEventListener('input', function() {
        const value = parseFloat(this.value);
        if (value > 100) {
            this.value = 100;
        }
        if (value < 0) {
            this.value = 0;
        }
    });
}

/**
 * Setup home button with confirmation
 */
function setupHomeButton() {
    const homeBtn = document.getElementById('homeBtn');
    homeBtn.addEventListener('click', function() {
        Swal.fire({
            title: 'Leave this page?',
            text: 'Any unsaved changes will be lost. Are you sure you want to go back to the home page?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, go to Home',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = '../ruchihomepage.html';
            }
        });
    });
}

/**
 * Setup form submission with validation and API call
 */
function setupFormSubmission(form) {
    const submitBtn = document.getElementById('submitBtn');
    const submitText = document.getElementById('submitText');
    const submitLoader = document.getElementById('submitLoader');

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        if (!validateForm()) {
            return;
        }

        // Show loading state
        submitText.style.display = 'none';
        submitLoader.style.display = 'inline';
        submitBtn.disabled = true;

        Swal.fire({
            title: 'Confirm Submission',
            text: 'Are you sure you want to submit your admission form?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, submit',
            cancelButtonText: 'Cancel',
            allowOutsideClick: false
        }).then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData(form);

                // Show processing message
                Swal.fire({
                    title: 'Processing...',
                    text: 'Please wait while we submit your form',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                fetch('admission_form.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        Swal.close();
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Success!',
                                html: data.message + '<br><br>Your admission ID: <strong>' + (data.admission_id || 'N/A') + '</strong><br><br>Would you like to create an account now?',
                                showCancelButton: true,
                                confirmButtonText: 'Yes, Sign Up',
                                cancelButtonText: 'Go to Home',
                                allowOutsideClick: false
                            }).then((res) => {
                                if (res.isConfirmed) {
                                    window.location.href = 'signup.html';
                                } else {
                                    window.location.href = '../ruchihomepage.html';
                                }
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Submission Failed',
                                html: data.message || 'Please try again later.'
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Network error. Please check your connection and try again.'
                        });
                    })
                    .finally(() => {
                        // Reset button state
                        submitText.style.display = 'inline';
                        submitLoader.style.display = 'none';
                        submitBtn.disabled = false;
                    });
            } else {
                // Reset button state if cancelled
                submitText.style.display = 'inline';
                submitLoader.style.display = 'none';
                submitBtn.disabled = false;
            }
        });
    });
}

/**
 * Validate entire form
 */
function validateForm() {
    let isValid = true;

    // Clear all previous errors
    clearAllErrors();

    // Required fields validation
    const requiredFields = [
        'first_name', 'last_name', 'father_name', 'mother_name',
        'dob', 'gender', 'class', 'medium', 'board', 'school',
        'previous_marks', 'parent_mobile', 'personal_mobile',
        'whatsapp', 'email', 'city', 'state', 'pincode', 'address'
    ];

    requiredFields.forEach(field => {
        const input = document.getElementById(field);
        const value = input.value.trim();

        if (!value) {
            showFieldError(field, 'This field is required');
            isValid = false;
            return;
        }

        // Specific validations
        switch (field) {
            case 'email':
                if (!validateEmail(value)) {
                    showFieldError(field, VALIDATION_RULES.email.message);
                    isValid = false;
                }
                break;

            case 'parent_mobile':
            case 'personal_mobile':
            case 'whatsapp':
                if (!validateMobile(value)) {
                    showFieldError(field, VALIDATION_RULES.mobile.message);
                    isValid = false;
                }
                break;

            case 'pincode':
                if (!validatePincode(value)) {
                    showFieldError(field, VALIDATION_RULES.pincode.message);
                    isValid = false;
                }
                break;

            case 'previous_marks':
                if (!validateMarks(value)) {
                    showFieldError(field, VALIDATION_RULES.marks.message);
                    isValid = false;
                }
                break;

            case 'dob':
                if (!validateDOB(value)) {
                    showFieldError(field, 'Student must be at least 5 years old');
                    isValid = false;
                }
                break;
        }
    });

    return isValid;
}

/**
 * Validation helper functions
 */
function validateEmail(email) {
    return VALIDATION_RULES.email.pattern.test(email);
}

function validateMobile(mobile) {
    return VALIDATION_RULES.mobile.pattern.test(mobile);
}

function validatePincode(pincode) {
    return VALIDATION_RULES.pincode.pattern.test(pincode);
}

function validateMarks(marks) {
    const value = parseFloat(marks);
    return !isNaN(value) && value >= VALIDATION_RULES.marks.min && value <= VALIDATION_RULES.marks.max;
}

function validateDOB(dobString) {
    const dob = new Date(dobString);
    const minAgeDate = new Date();
    minAgeDate.setFullYear(minAgeDate.getFullYear() - 5);
    return dob <= minAgeDate;
}

/**
 * Display field error with ARIA attributes
 */
function showFieldError(fieldId, message) {
    const input = document.getElementById(fieldId);
    const errorElement = document.getElementById(fieldId + '_error');

    input.classList.add('error-field');
    input.classList.remove('success-field');
    input.setAttribute('aria-invalid', 'true');
    input.setAttribute('aria-describedby', fieldId + '_error');

    if (errorElement) {
        errorElement.textContent = message;
        errorElement.style.display = 'block';
        errorElement.setAttribute('role', 'alert');
    }

    // Scroll to error field on mobile
    if (window.innerWidth <= 768) {
        input.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

/**
 * Clear field error
 */
function clearFieldError(fieldId) {
    const input = document.getElementById(fieldId);
    const errorElement = document.getElementById(fieldId + '_error');

    input.classList.remove('error-field');
    input.classList.add('success-field');
    input.setAttribute('aria-invalid', 'false');

    if (errorElement) {
        errorElement.style.display = 'none';
    }
}

/**
 * Clear all form errors
 */
function clearAllErrors() {
    const errorMessages = document.querySelectorAll('.error-message');
    const inputs = document.querySelectorAll('input, select, textarea');

    errorMessages.forEach(el => {
        el.style.display = 'none';
    });

    inputs.forEach(input => {
        input.classList.remove('error-field');
        input.classList.remove('success-field');
        input.setAttribute('aria-invalid', 'false');
    });
}

/**
 * Auto-dismiss success field styling after input change
 */
document.addEventListener('input', function(e) {
    if (e.target.matches('input, select, textarea')) {
        if (e.target.classList.contains('success-field')) {
            e.target.classList.remove('success-field');
        }
    }
});

/**
 * Prevent form resubmission on page refresh
 */
function preventFormResubmission() {
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
}

/**
 * Handle back button on mobile
 */
function handleBackButton() {
    window.addEventListener('pageshow', function(event) {
        if (event.persisted) {
            window.location.reload();
        }
    });
}
