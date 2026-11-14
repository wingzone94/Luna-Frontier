export function initCommentForm() {
    const commentForm = document.getElementById('commentform');
    if (!commentForm) return;

    const submitBtn = commentForm.querySelector('.m3-comment-submit-btn');
    const requiredFields = commentForm.querySelectorAll('[required]');

    const checkValidity = () => {
        let isValid = true;
        requiredFields.forEach(field => {
            if (!field.value.trim()) isValid = false;
        });

        if (isValid) {
            submitBtn.removeAttribute('disabled');
            submitBtn.classList.add('is-ready');
        } else {
            submitBtn.setAttribute('disabled', 'disabled');
            submitBtn.classList.remove('is-ready');
        }
    };

    requiredFields.forEach(field => {
        field.addEventListener('input', checkValidity);
    });

    commentForm.addEventListener('submit', () => {
        if (submitBtn) {
            submitBtn.classList.add('is-submitting');
            submitBtn.innerHTML = '送信中...<span class="material-symbols-outlined" data-icon="schedule"></span>';
            submitBtn.style.backgroundColor = '#2196F3';
            submitBtn.style.pointerEvents = 'none';
        }
    });

    checkValidity();
}
