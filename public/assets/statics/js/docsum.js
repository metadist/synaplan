// Document Summary Tool JavaScript
// Handles form submission, validation, and UI interactions for document summarization

// Load page when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    initializeForm();
});

// Initialize form functionality
function initializeForm() {
    // Handle input method changes
    document.querySelectorAll('input[name="inputMethod"]').forEach(radio => {
        radio.addEventListener('change', toggleInputSections);
    });

    // Handle summary length changes
    document.getElementById('summaryLength').addEventListener('change', toggleCustomLength);

    // Handle text input changes for character/word counting
    document.getElementById('BFILETEXT').addEventListener('input', updateCounts);

    // Handle form submission
    document.getElementById('docSummaryForm').addEventListener('submit', handleFormSubmit);
}

// Toggle input sections based on selected method
function toggleInputSections() {
    const sections = document.querySelectorAll('.input-section');
    sections.forEach(section => section.style.display = 'none');
    
    const selectedMethod = document.querySelector('input[name="inputMethod"]:checked').value;
    document.getElementById(selectedMethod + 'Section').style.display = 'block';
}

// Toggle custom length input
function toggleCustomLength() {
    const customLength = document.getElementById('customLength');
    const isCustom = document.getElementById('summaryLength').value === 'custom';
    customLength.disabled = !isCustom;
}

// Update character and word counts
function updateCounts() {
    const text = document.getElementById('BFILETEXT').value;
    const charCount = text.length;
    const wordCount = text.trim() === '' ? 0 : text.trim().split(/\s+/).length;
    const estimatedTokens = Math.ceil(charCount / 3.5); // Rough estimation

    document.getElementById('charCount').textContent = charCount.toLocaleString();
    document.getElementById('wordCount').textContent = wordCount.toLocaleString();
    document.getElementById('estimatedTokens').textContent = estimatedTokens.toLocaleString();
}

// Handle form submission via AJAX
function handleFormSubmit(event) {
    event.preventDefault();
    
    // Validate form
    if (!validateForm()) {
        return false;
    }

    // Show loading state
    showLoadingState();

    // Collect form data
    const formData = new FormData();
    formData.append('action', 'docSum');
    formData.append('BFILETEXT', document.getElementById('BFILETEXT').value);
    formData.append('summaryType', document.getElementById('summaryType').value);
    formData.append('summaryLength', document.getElementById('summaryLength').value);
    formData.append('language', document.getElementById('language').value);
    
    // Add custom length if selected
    if (document.getElementById('summaryLength').value === 'custom') {
        formData.append('customLength', document.getElementById('customLength').value);
    }

    // Add focus areas
    const focusAreas = document.querySelectorAll('input[name="focusAreas[]"]:checked');
    focusAreas.forEach(checkbox => {
        formData.append('focusAreas[]', checkbox.value);
    });

    // Submit via AJAX
    fetch('api.php', {
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
        console.log('Server response:', data);
        if (data.success) {
            displaySummary(data.summary);
        } else {
            showError(data.error || 'An error occurred while generating the summary.');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showError('An error occurred while processing your request. Please try again.');
    })
    .finally(() => {
        hideLoadingState();
    });
}

// Validate form before submission
function validateForm() {
    const documentText = document.getElementById('BFILETEXT').value.trim();
    
    if (!documentText) {
        showError('Please enter some text to summarize.');
        return false;
    }

    if (documentText.length < 10) {
        showError('Please enter at least 10 characters of text to summarize.');
        return false;
    }

    // Validate custom length if selected
    if (document.getElementById('summaryLength').value === 'custom') {
        const customLength = parseInt(document.getElementById('customLength').value);
        if (isNaN(customLength) || customLength < 50 || customLength > 2000) {
            showError('Custom length must be between 50 and 2000 words.');
            return false;
        }
    }

    return true;
}

// Show loading state
function showLoadingState() {
    const submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating Summary...';
    
    // Show loading indicator in results section
    document.getElementById('resultsSection').style.display = 'block';
    document.getElementById('summaryContent').innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3 text-muted">Generating summary, please wait...</p>
        </div>
    `;
}

// Hide loading state
function hideLoadingState() {
    const submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = false;
    submitBtn.innerHTML = '<i class="fas fa-magic"></i> Generate Summary';
}

// Display the generated summary
function displaySummary(summary) {
    document.getElementById('summaryContent').innerHTML = `
        <div class="summary-result">
            <div class="summary-text">
                ${summary.replace(/\n/g, '<br>')}
            </div>
            <div class="summary-meta mt-3">
                <small class="text-muted">
                    <i class="fas fa-clock"></i> Generated on ${new Date().toLocaleString()}
                </small>
            </div>
        </div>
    `;
}

// Show error message
function showError(message) {
    document.getElementById('resultsSection').style.display = 'block';
    document.getElementById('summaryContent').innerHTML = `
        <div class="alert alert-danger" role="alert">
            <i class="fas fa-exclamation-triangle"></i> ${message}
        </div>
    `;
}

// Clear form
function clearForm() {
    document.getElementById('docSummaryForm').reset();
    document.getElementById('BFILETEXT').value = '';
    updateCounts();
    document.getElementById('resultsSection').style.display = 'none';
}

// Download summary
function downloadSummary() {
    const summaryContent = document.getElementById('summaryContent').textContent;
    if (!summaryContent) {
        showError('No summary available to download.');
        return;
    }

    const blob = new Blob([summaryContent], { type: 'text/plain' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'document-summary.txt';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

// Copy summary to clipboard
function copySummary() {
    const summaryContent = document.getElementById('summaryContent').textContent;
    if (!summaryContent) {
        showError('No summary available to copy.');
        return;
    }

    navigator.clipboard.writeText(summaryContent).then(() => {
        // Show success message
        const originalText = document.querySelector('.btn-outline-secondary').innerHTML;
        document.querySelector('.btn-outline-secondary').innerHTML = '<i class="fas fa-check"></i> Copied!';
        setTimeout(() => {
            document.querySelector('.btn-outline-secondary').innerHTML = originalText;
        }, 2000);
    }).catch(err => {
        console.error('Failed to copy: ', err);
        showError('Failed to copy to clipboard.');
    });
} 