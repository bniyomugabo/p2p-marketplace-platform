
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('testForm');
    const messageInput = document.getElementById('message');
    const responseDiv = document.getElementById('response');
    const sendBtn = document.getElementById('sendBtn');
    const loadingDiv = document.getElementById('loading');
    const clearBtn = document.getElementById('clearBtn');
    const quickButtons = document.querySelectorAll('.quick-message');

    // Handle form submission
    form.addEventListener('submit', async function (e) {
        e.preventDefault();

        const message = messageInput.value.trim();
        if (!message) {
            showResponse('Please enter a message', 'error');
            return;
        }

        // Show loading, hide response
        loadingDiv.style.display = 'block';
        responseDiv.innerHTML = '';
        sendBtn.disabled = true;

        // Prepare form data
        const formData = new FormData();
        formData.append('message', message);
        formData.append('csrf_token', '<?php echo $csrfToken; ?>');

        try {
            // Send to API
            const response = await fetch('./api/products/testing.php', {
                method: 'POST',
                body: formData,
                credentials: 'include'
            });

            // Get response text
            const responseText = await response.text();

            // Try to parse as JSON
            let responseData;
            try {
                responseData = JSON.parse(responseText);
            } catch (e) {
                responseData = { raw: responseText };
            }

            // Display response
            showResponse(responseData, 'success');

        } catch (error) {
            showResponse('Error: ' + error.message, 'error');
        } finally {
            loadingDiv.style.display = 'none';
            sendBtn.disabled = false;
        }
    });

    // Display response function
    function showResponse(data, type) {
        let html = '';

        if (type === 'error') {
            html = `<div class="response-error">${data}</div>`;
        } else {
            // Format JSON response nicely
            html = '<div class="mb-2"><span class="badge bg-success">Success</span></div>';
            html += '<pre style="margin:0; white-space: pre-wrap; word-wrap: break-word;">' +
                JSON.stringify(data, null, 2) + '</pre>';
        }

        responseDiv.innerHTML = html;
    }

    // Clear response
    clearBtn.addEventListener('click', function () {
        responseDiv.innerHTML = '<div class="text-muted text-center py-4">' +
            '<i class="fas fa-arrow-up me-2"></i>' +
            'Send a message to see the response here</div>';
        messageInput.value = '';
        messageInput.focus();
    });

    // Quick message buttons
    quickButtons.forEach(btn => {
        btn.addEventListener('click', function () {
            const message = this.getAttribute('data-message');
            messageInput.value = message;
            messageInput.focus();
        });
    });

    // Auto-resize textarea (optional)
    messageInput.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    });
});