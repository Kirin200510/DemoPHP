const uploadInput = document.querySelector('#image');
const uploadForm = document.querySelector('#document-upload-form');

if (uploadInput && uploadForm) {
    const preview = document.querySelector('#image-preview');
    const placeholder = document.querySelector('#upload-placeholder');
    const selectedFile = document.querySelector('#selected-file');
    const selectedFileName = document.querySelector('#selected-file-name');
    const removeButton = document.querySelector('#remove-selected-file');
    const processButton = document.querySelector('#process-button');
    const processButtonLabel = document.querySelector('#process-button-label');
    const processSpinner = document.querySelector('#process-spinner');
    let previewUrl;

    const updateProcessButton = () => {
        processButton.disabled = uploadInput.files.length === 0;
    };

    const updatePreview = () => {
        const [file] = uploadInput.files;

        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
            previewUrl = undefined;
        }

        if (!file) {
            preview.removeAttribute('src');
            preview.classList.add('hidden');
            placeholder.classList.remove('hidden');
            selectedFile.classList.add('hidden');
            selectedFile.classList.remove('flex');
            updateProcessButton();
            return;
        }

        previewUrl = URL.createObjectURL(file);
        preview.src = previewUrl;
        preview.classList.remove('hidden');
        placeholder.classList.add('hidden');
        selectedFileName.textContent = file.name;
        selectedFile.classList.remove('hidden');
        selectedFile.classList.add('flex');
        updateProcessButton();
    };

    uploadInput.addEventListener('change', updatePreview);

    removeButton.addEventListener('click', () => {
        uploadInput.value = '';
        updatePreview();
        uploadInput.click();
    });

    uploadForm.addEventListener('submit', (event) => {
        if (uploadInput.files.length === 0) {
            event.preventDefault();
            updateProcessButton();
            return;
        }

        processButton.disabled = true;
        processButton.classList.add('cursor-wait');
        processButtonLabel.textContent = 'AI đang xử lý...';
        processSpinner.classList.remove('hidden');
    });

    updateProcessButton();
}

document.querySelectorAll('[data-password-toggle]').forEach((toggle) => {
    const input = document.getElementById(toggle.dataset.passwordToggle);

    if (!input) {
        return;
    }

    toggle.addEventListener('click', () => {
        const shouldShowPassword = input.type === 'password';
        const eye = toggle.querySelector('[data-password-eye]');
        const eyeOff = toggle.querySelector('[data-password-eye-off]');

        input.type = shouldShowPassword ? 'text' : 'password';
        eye?.classList.toggle('hidden', shouldShowPassword);
        eyeOff?.classList.toggle('hidden', !shouldShowPassword);
        toggle.setAttribute('aria-label', shouldShowPassword ? 'Ẩn mật khẩu' : 'Hiện mật khẩu');
        toggle.setAttribute('title', shouldShowPassword ? 'Ẩn mật khẩu' : 'Hiện mật khẩu');
        toggle.setAttribute('aria-pressed', shouldShowPassword ? 'true' : 'false');
    });
});
