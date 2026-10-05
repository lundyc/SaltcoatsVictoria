(() => {
    const input = document.getElementById('profileImage');
    const preview = document.getElementById('profilePhotoPreview');
    const status = document.getElementById('profilePhotoStatus');
    if (!input || !preview || !status) return;
    const originalSrc = preview.getAttribute('src');
    const originallyHidden = preview.hidden;
    const initials = document.getElementById('profilePhotoInitials');
    let previewUrl = null;
    input.addEventListener('change', () => {
        const file = input.files[0];
        if (!file) return;
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) {
            input.value = '';
            if (previewUrl) URL.revokeObjectURL(previewUrl);
            previewUrl = null;
            if (originalSrc) preview.src = originalSrc;
            else preview.removeAttribute('src');
            preview.hidden = originallyHidden;
            if (initials) initials.hidden = !originallyHidden;
            status.textContent = 'Choose a JPG, PNG or WebP image under 2MB.';
            return;
        }
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = URL.createObjectURL(file);
        preview.src = previewUrl;
        preview.hidden = false;
        if (initials) initials.hidden = true;
        status.textContent = 'New photo selected. Save changes to update your profile.';
    });
    window.addEventListener('pagehide', () => {
        if (previewUrl) URL.revokeObjectURL(previewUrl);
    });
})();
