// assets/js/alerts.js - Branded Notyf & Confirmation Modal System

(function() {
    // Initialize Notyf globally with #12b1b0 brand color
    let notyfInstance = null;

    function initNotyf() {
        if (typeof Notyf !== 'undefined') {
            notyfInstance = new Notyf({
                duration: 4000,
                position: { x: 'right', y: 'top' },
                dismissible: true,
                types: [
                    {
                        type: 'success',
                        background: '#12b1b0',
                        icon: false
                    },
                    {
                        type: 'error',
                        background: '#ef4444',
                        icon: false
                    },
                    {
                        type: 'warning',
                        background: '#f59e0b',
                        icon: false
                    },
                    {
                        type: 'info',
                        background: '#0f172a',
                        icon: false
                    }
                ]
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initNotyf);
    } else {
        initNotyf();
    }

    // Global showToast helper function
    window.showToast = function(message, type = 'success') {
        if (!notyfInstance) initNotyf();
        
        if (notyfInstance) {
            const normalizedType = type === 'danger' ? 'error' : type;
            if (typeof notyfInstance[normalizedType] === 'function') {
                notyfInstance[normalizedType](message);
            } else {
                notyfInstance.open({ type: normalizedType, message: message });
            }
        } else {
            console.log(`[Toast ${type}]:`, message);
        }
    };

    // Helper function to mark notifications as read in DB via AJAX
    window.markAlertsAsRead = function(ids) {
        if (!Array.isArray(ids) || ids.length === 0) return;
        const baseUrl = typeof window.BASE_URL !== 'undefined' ? window.BASE_URL : '';
        fetch(baseUrl + '/ajax/notifications/mark_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ids: ids })
        }).catch(err => console.error('Error marking notifications as read:', err));
    };

    // Handle PHP unread alerts automatically (triggers only on login)
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof window.unreadAlerts !== 'undefined' && Array.isArray(window.unreadAlerts) && window.unreadAlerts.length > 0) {
            window.unreadAlerts.forEach((alert, index) => {
                setTimeout(() => {
                    window.showToast(alert.message, alert.type || 'info');
                }, index * 400);
            });
        }
    });

    // --- Custom Branded Confirmation Dialog System ---
    window.appConfirm = function(options) {
        return new Promise((resolve) => {
            const title = options.title || 'Confirm Action';
            const message = options.message || 'Are you sure you want to proceed?';
            const confirmText = options.confirmText || 'Confirm';
            const cancelText = options.cancelText || 'Cancel';
            const isDanger = options.isDanger !== false; // default true for dangerous actions

            // Create Modal Overlay
            const overlay = document.createElement('div');
            overlay.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px);
                display: flex; align-items: center; justify-content: center;
                z-index: 99999; opacity: 0; transition: opacity 0.2s ease;
            `;

            const modal = document.createElement('div');
            modal.style.cssText = `
                background: #ffffff; border-radius: 14px; width: 90%; max-width: 440px;
                padding: 1.75rem; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
                transform: scale(0.95); transition: transform 0.2s ease; border: 1px solid #e2e8f0;
            `;

            const btnBg = isDanger ? '#ef4444' : '#12b1b0';

            modal.innerHTML = `
                <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem;">
                    <div style="width: 40px; height: 40px; border-radius: 50%; background: ${isDanger ? '#fee2e2' : '#e0f2f1'}; color: ${btnBg}; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                        <i data-lucide="${isDanger ? 'alert-triangle' : 'help-circle'}"></i>
                    </div>
                    <h3 style="margin: 0; font-size: 1.15rem; font-weight: 700; color: #0f172a;">${title}</h3>
                </div>
                <p style="margin: 0 0 1.5rem 0; color: #64748b; font-size: 0.9rem; line-height: 1.5;">${message}</p>
                <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                    <button id="confirmCancelBtn" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 0.55rem 1.1rem; border-radius: 8px; font-weight: 600; font-size: 0.875rem; cursor: pointer; transition: all 0.2s;">${cancelText}</button>
                    <button id="confirmOkBtn" style="background: ${btnBg}; color: #ffffff; border: none; padding: 0.55rem 1.25rem; border-radius: 8px; font-weight: 600; font-size: 0.875rem; cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 12px ${isDanger ? 'rgba(239, 68, 68, 0.25)' : 'rgba(18, 177, 176, 0.25)'};">${confirmText}</button>
                </div>
            `;

            overlay.appendChild(modal);
            document.body.appendChild(overlay);

            if (window.lucide) {
                lucide.createIcons();
            }

            requestAnimationFrame(() => {
                overlay.style.opacity = '1';
                modal.style.transform = 'scale(1)';
            });

            function cleanup(result) {
                overlay.style.opacity = '0';
                modal.style.transform = 'scale(0.95)';
                setTimeout(() => {
                    if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
                    resolve(result);
                }, 200);
            }

            modal.querySelector('#confirmCancelBtn').addEventListener('click', () => cleanup(false));
            modal.querySelector('#confirmOkBtn').addEventListener('click', () => cleanup(true));
        });
    };

    // --- Custom Branded Input/Prompt Modal Dialog System ---
    window.appPrompt = function(options) {
        return new Promise((resolve) => {
            const title = options.title || 'Provide Details';
            const message = options.message || 'Please enter details below:';
            const placeholder = options.placeholder || 'Enter details...';
            const confirmText = options.confirmText || 'Submit';
            const cancelText = options.cancelText || 'Cancel';
            const isDanger = options.isDanger !== false;
            const required = options.required !== false;

            const overlay = document.createElement('div');
            overlay.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px);
                display: flex; align-items: center; justify-content: center;
                z-index: 99999; opacity: 0; transition: opacity 0.2s ease;
            `;

            const modal = document.createElement('div');
            modal.style.cssText = `
                background: #ffffff; border-radius: 14px; width: 90%; max-width: 460px;
                padding: 1.75rem; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
                transform: scale(0.95); transition: transform 0.2s ease; border: 1px solid #e2e8f0;
            `;

            const btnBg = isDanger ? '#ef4444' : '#12b1b0';

            modal.innerHTML = `
                <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem;">
                    <div style="width: 40px; height: 40px; border-radius: 50%; background: ${isDanger ? '#fee2e2' : '#e0f2f1'}; color: ${btnBg}; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                        <i data-lucide="${isDanger ? 'alert-triangle' : 'message-square'}"></i>
                    </div>
                    <h3 style="margin: 0; font-size: 1.15rem; font-weight: 700; color: #0f172a;">${title}</h3>
                </div>
                <p style="margin: 0 0 1rem 0; color: #64748b; font-size: 0.9rem; line-height: 1.5;">${message}</p>
                <div style="margin-bottom: 1.5rem;">
                    <textarea id="appPromptInput" rows="3" placeholder="${placeholder}" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 0.75rem; font-size: 0.875rem; font-family: inherit; resize: vertical; outline: none; transition: border-color 0.2s;" onfocus="this.style.borderColor='${btnBg}'" onblur="this.style.borderColor='#cbd5e1'"></textarea>
                    <div id="appPromptError" style="color: #ef4444; font-size: 0.8rem; margin-top: 0.35rem; display: none;">Rejection reason is required.</div>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                    <button id="promptCancelBtn" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 0.55rem 1.1rem; border-radius: 8px; font-weight: 600; font-size: 0.875rem; cursor: pointer; transition: all 0.2s;">${cancelText}</button>
                    <button id="promptOkBtn" style="background: ${btnBg}; color: #ffffff; border: none; padding: 0.55rem 1.25rem; border-radius: 8px; font-weight: 600; font-size: 0.875rem; cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 12px ${isDanger ? 'rgba(239, 68, 68, 0.25)' : 'rgba(18, 177, 176, 0.25)'};">${confirmText}</button>
                </div>
            `;

            overlay.appendChild(modal);
            document.body.appendChild(overlay);

            if (window.lucide) {
                lucide.createIcons();
            }

            const inputEl = modal.querySelector('#appPromptInput');
            const errorEl = modal.querySelector('#appPromptError');

            requestAnimationFrame(() => {
                overlay.style.opacity = '1';
                modal.style.transform = 'scale(1)';
                inputEl.focus();
            });

            function cleanup(value) {
                overlay.style.opacity = '0';
                modal.style.transform = 'scale(0.95)';
                setTimeout(() => {
                    if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
                    resolve(value);
                }, 200);
            }

            modal.querySelector('#promptCancelBtn').addEventListener('click', () => cleanup(null));
            modal.querySelector('#promptOkBtn').addEventListener('click', () => {
                const val = inputEl.value.trim();
                if (required && !val) {
                    errorEl.style.display = 'block';
                    inputEl.style.borderColor = '#ef4444';
                    return;
                }
                cleanup(val);
            });
        });
    };

    // Legacy function wrapper for easy replacements
    window.confirmAction = function(message, callback) {
        window.appConfirm({
            title: 'Confirm Action',
            message: message,
            confirmText: 'Yes, proceed',
            cancelText: 'Cancel'
        }).then(confirmed => {
            if (confirmed && typeof callback === 'function') {
                callback();
            }
        });
    };

})();
