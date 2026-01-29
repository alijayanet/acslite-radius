// Telegram Bot Management Functions for settings.html
// Long Polling Mode Only

// Check Telegram bot service status
async function checkTelegramService() {
    const statusBadge = document.getElementById('telegram-service-status');
    statusBadge.textContent = 'Checking...';
    statusBadge.className = 'badge bg-secondary';

    try {
        const response = await fetch(`${SETTINGS_API}?action=telegram_service_status`);
        const result = await response.json();

        if (result.success) {
            if (result.status === 'active') {
                statusBadge.textContent = '● Running';
                statusBadge.className = 'badge bg-success';
            } else if (result.status === 'inactive') {
                statusBadge.textContent = '○ Stopped';
                statusBadge.className = 'badge bg-danger';
            } else {
                statusBadge.textContent = '? Unknown';
                statusBadge.className = 'badge bg-warning';
            }
        } else {
            statusBadge.textContent = 'Error';
            statusBadge.className = 'badge bg-danger';
        }
    } catch (error) {
        console.error('Error checking service:', error);
        statusBadge.textContent = 'Error';
        statusBadge.className = 'badge bg-danger';
    }
}

// Start Telegram bot service
async function startTelegramBot() {
    if (!confirm('Start Telegram bot service?')) return;

    try {
        const response = await fetch(`${SETTINGS_API}?action=telegram_service_start`, {
            method: 'POST'
        });
        const result = await response.json();

        if (result.success) {
            alert('✅ Telegram bot started successfully!');
            checkTelegramService();
        } else {
            alert('❌ Failed to start bot: ' + (result.message || 'Unknown error'));
        }
    } catch (error) {
        alert('❌ Error: ' + error.message);
    }
}

// Stop Telegram bot service
async function stopTelegramBot() {
    if (!confirm('Stop Telegram bot service?')) return;

    try {
        const response = await fetch(`${SETTINGS_API}?action=telegram_service_stop`, {
            method: 'POST'
        });
        const result = await response.json();

        if (result.success) {
            alert('✅ Telegram bot stopped successfully!');
            checkTelegramService();
        } else {
            alert('❌ Failed to stop bot: ' + (result.message || 'Unknown error'));
        }
    } catch (error) {
        alert('❌ Error: ' + error.message);
    }
}

// Restart Telegram bot service
async function restartTelegramBot() {
    if (!confirm('Restart Telegram bot service?')) return;

    try {
        const response = await fetch(`${SETTINGS_API}?action=telegram_service_restart`, {
            method: 'POST'
        });
        const result = await response.json();

        if (result.success) {
            alert('✅ Telegram bot restarted successfully!');
            setTimeout(() => checkTelegramService(), 2000); // Wait 2s before checking
        } else {
            alert('❌ Failed to restart bot: ' + (result.message || 'Unknown error'));
        }
    } catch (error) {
        alert('❌ Error: ' + error.message);
    }
}

// View Telegram bot logs
async function viewTelegramLogs() {
    try {
        const response = await fetch(`${SETTINGS_API}?action=telegram_service_logs`);
        const result = await response.json();

        if (result.success) {
            const logs = result.logs || 'No logs available';

            // Create modal to show logs
            const modal = document.createElement('div');
            modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:9999;display:flex;align-items:center;justify-content:center;';
            modal.innerHTML = `
                <div style="background:white;padding:20px;border-radius:8px;max-width:800px;max-height:80vh;overflow:auto;width:90%;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
                        <h5><i class="fas fa-file-alt"></i> Telegram Bot Logs</h5>
                        <button onclick="this.closest('div[style*=fixed]').remove()" class="btn btn-sm btn-danger">
                            <i class="fas fa-times"></i> Close
                        </button>
                    </div>
                    <pre style="background:#1e1e1e;color:#d4d4d4;padding:15px;border-radius:4px;max-height:60vh;overflow:auto;font-size:12px;">${logs}</pre>
                </div>
            `;
            document.body.appendChild(modal);

            // Close on background click
            modal.addEventListener('click', (e) => {
                if (e.target === modal) modal.remove();
            });
        } else {
            alert('❌ Failed to load logs: ' + (result.message || 'Unknown error'));
        }
    } catch (error) {
        alert('❌ Error: ' + error.message);
    }
}

// Enhanced saveTelegram function (Long Polling only)
async function saveTelegram() {
    const adminChatIds = document.getElementById('telegram-admin_chat_ids').value;
    const chatId = document.getElementById('telegram-chat_id').value;

    // Parse admin chat IDs
    const chatIdsArray = adminChatIds.split(',').map(id => id.trim()).filter(id => id);

    const data = {
        enabled: document.getElementById('telegram-enabled').checked,
        bot_token: document.getElementById('telegram-bot_token').value,
        chat_id: chatId,
        mode: 'polling', // Always long polling
        admin_chat_ids: chatIdsArray,
        notify_isolir: document.getElementById('telegram-notify_isolir').checked,
        notify_payment: document.getElementById('telegram-notify_payment').checked,
        notify_new_device: document.getElementById('telegram-notify_new_device').checked
    };

    try {
        const response = await fetch(`${SETTINGS_API}?action=save_telegram`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ telegram: data })
        });

        const result = await response.json();

        if (result.success) {
            alert('✅ Telegram settings saved successfully!');

            // If bot is enabled, suggest starting the service
            if (data.enabled) {
                if (confirm('Bot is configured in Long Polling mode.\n\nWould you like to start the bot service now?')) {
                    await startTelegramBot();
                }
            }
        } else {
            alert('❌ Failed to save settings: ' + (result.message || 'Unknown error'));
        }
    } catch (error) {
        alert('❌ Error: ' + error.message);
    }
}

// Test Telegram notification
async function testTelegram() {
    try {
        const response = await fetch(`${SETTINGS_API}?action=test_telegram`, {
            method: 'POST'
        });
        const result = await response.json();

        if (result.success) {
            alert('✅ Test notification sent! Check your Telegram.');
        } else {
            alert('❌ Failed to send test: ' + (result.message || 'Unknown error'));
        }
    } catch (error) {
        alert('❌ Error: ' + error.message);
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    // Check service status on load
    const telegramSection = document.getElementById('section-telegram');
    if (telegramSection) {
        checkTelegramService();
    }
});
