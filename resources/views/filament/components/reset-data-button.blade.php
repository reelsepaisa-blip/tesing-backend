@php
    $user = filament()->auth()->user();
@endphp

@if($user && $user->id === 1)
    <style>
        .reset-data-btn {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            color: white;
            border: none;
            padding: 0.625rem 1.25rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.875rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 6px -1px rgba(220, 38, 38, 0.3), 0 2px 4px -1px rgba(220, 38, 38, 0.2);
            position: relative;
            overflow: hidden;
        }

        .reset-data-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .reset-data-btn:hover::before {
            left: 100%;
        }

        .reset-data-btn:hover {
            background: linear-gradient(135deg, #b91c1c 0%, #991b1b 100%);
            box-shadow: 0 10px 15px -3px rgba(220, 38, 38, 0.4), 0 4px 6px -2px rgba(220, 38, 38, 0.3);
            transform: translateY(-1px);
        }

        .reset-data-btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 4px -1px rgba(220, 38, 38, 0.3);
        }

        .reset-data-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .reset-data-btn svg {
            width: 1.125rem;
            height: 1.125rem;
            stroke-width: 2;
        }

        .reset-data-btn .spinner {
            display: none;
            width: 1rem;
            height: 1rem;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }

        .reset-data-btn.loading .spinner {
            display: block;
        }

        .reset-data-btn.loading .btn-text {
            display: none;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .premium-toast {
            position: fixed;
            top: 1.5rem;
            right: 1.5rem;
            padding: 1rem 1.5rem;
            border-radius: 0.75rem;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            z-index: 9999;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 500;
            animation: slideIn 0.3s ease-out;
            max-width: 400px;
        }

        .premium-toast.success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }

        .premium-toast.error {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
    </style>

    <button
        type="button"
        id="reset-data-btn"
        class="reset-data-btn"
    >
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
        </svg>
        <span class="btn-text">Reset Data</span>
        <div class="spinner"></div>
    </button>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const resetBtn = document.getElementById('reset-data-btn');
            if (resetBtn) {
                resetBtn.addEventListener('click', async function() {
                    if (!confirm('⚠️ Are you sure you want to reset all data?\n\nThis will permanently delete and truncate data from multiple tables. This action cannot be undone.')) {
                        return;
                    }

                    try {
                        const button = resetBtn;
                        const originalText = button.querySelector('.btn-text').textContent;
                        button.disabled = true;
                        button.classList.add('loading');
                        button.querySelector('.btn-text').textContent = 'Resetting...';

                        const response = await fetch('{{ route("admin.reset-data") }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                            }
                        });

                        const data = await response.json();

                        if (data.success) {
                            showToast('✅ ' + data.message, 'success');
                            setTimeout(() => {
                                window.location.reload();
                            }, 1500);
                        } else {
                            showToast('❌ ' + data.message, 'error');
                            button.disabled = false;
                            button.classList.remove('loading');
                            button.querySelector('.btn-text').textContent = originalText;
                        }
                    } catch (error) {
                        showToast('❌ Error: ' + error.message, 'error');
                        button.disabled = false;
                        button.classList.remove('loading');
                        button.querySelector('.btn-text').textContent = 'Reset Data';
                    }
                });
            }

            function showToast(message, type) {
                // Remove existing toasts
                const existingToasts = document.querySelectorAll('.premium-toast');
                existingToasts.forEach(toast => toast.remove());

                const toast = document.createElement('div');
                toast.className = `premium-toast ${type}`;
                toast.innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        ${type === 'success' 
                            ? '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline>'
                            : '<circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line>'
                        }
                    </svg>
                    <span>${message}</span>
                `;
                document.body.appendChild(toast);

                setTimeout(() => {
                    toast.style.animation = 'slideIn 0.3s ease-out reverse';
                    setTimeout(() => toast.remove(), 300);
                }, 3000);
            }
        });
    </script>
@endif
