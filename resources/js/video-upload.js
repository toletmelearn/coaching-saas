import { Upload } from 'tus-js-client';

/**
 * Drives the "Upload video" / "Replace video" flow on the lesson edit page
 * (resources/views/manage/lessons/edit.blade.php). No-ops on any page without a
 * #video-manager element. See docs/specs/phase-5-video.md.
 */
export function initVideoUpload() {
    const input = document.getElementById('video-file-input');
    const manager = document.getElementById('video-manager');

    if (!input || !manager) {
        return;
    }

    const progressTrack = document.getElementById('video-progress-track');
    const progressBar = document.getElementById('video-progress-bar');
    const errorEl = document.getElementById('video-upload-error');
    const lessonId = manager.dataset.lessonId;
    const uploadFailedMessage = manager.dataset.uploadFailedMessage;
    const csrfTokenMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfTokenMeta ? csrfTokenMeta.content : '';

    function showError(message) {
        errorEl.textContent = message;
        errorEl.classList.remove('hidden');
    }

    input.addEventListener('change', function () {
        const file = input.files[0];
        if (!file) {
            return;
        }

        errorEl.classList.add('hidden');
        progressTrack.classList.remove('hidden');
        progressBar.style.width = '0%';

        // Our own route — carries the session's CSRF token.
        fetch('/manage/lessons/' + lessonId + '/video/start-upload', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                Accept: 'application/json',
            },
            body: JSON.stringify({ filename: file.name, mime_type: file.type, size_bytes: file.size }),
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('start-upload-failed');
                }

                return response.json();
            })
            .then(function (data) {
                if (data.driver === 'bunny') {
                    // TUS uploads go straight to Bunny's own server, not one of our
                    // routes — no CSRF token involved, just the TUS signature headers
                    // the server already computed.
                    new Upload(file, {
                        endpoint: data.tus_endpoint,
                        headers: data.headers,
                        metadata: data.metadata,
                        onProgress: function (bytesUploaded, bytesTotal) {
                            progressBar.style.width = Math.round((bytesUploaded / bytesTotal) * 100) + '%';
                        },
                        onSuccess: function () {
                            window.location.reload();
                        },
                        onError: function () {
                            showError(uploadFailedMessage);
                        },
                    }).start();

                    return;
                }

                if (data.upload_url) {
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', data.upload_url);
                    // Our own route — carries the session's CSRF token.
                    xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken);
                    xhr.upload.addEventListener('progress', function (e) {
                        if (e.lengthComputable) {
                            progressBar.style.width = Math.round((e.loaded / e.total) * 100) + '%';
                        }
                    });
                    xhr.onload = function () {
                        if (xhr.status >= 200 && xhr.status < 300) {
                            window.location.reload();
                        } else {
                            showError(uploadFailedMessage);
                        }
                    };
                    const formData = new FormData();
                    formData.append('file', file);
                    xhr.send(formData);
                }
            })
            .catch(function () {
                showError(uploadFailedMessage);
            });
    });
}
