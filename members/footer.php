<?php
// Feedback bar/fab/modal disabled site-wide for now — flip this back to true
// to bring it back rather than re-adding the markup below.
const FEEDBACK_BAR_ENABLED = false;
require_once __DIR__ . '/../admin/lib/feedback.php';
ensureFeedbackSchema($pdo);
$feedbackPersonId = member_auth_current_person_id() ?? (is_array($currentHolder) ? (int) ($currentHolder['person_id'] ?? 0) : 0);
$feedbackLegacyHolderId = member_auth_current_legacy_holder_id() ?? (is_array($currentHolder) ? (int) ($currentHolder['legacy_holder_id'] ?? $currentHolder['id'] ?? 0) : 0);
$hasSubmittedFeedback = $currentHolder && $feedbackPersonId > 0 ? personHasSubmittedFeedback($pdo, $feedbackPersonId, $feedbackLegacyHolderId) : true;
$feedbackCsrf = $currentHolder ? member_auth_csrf_token() : '';
$feedbackPage = basename($_SERVER['PHP_SELF'] ?? '');
?>
<?php if ($currentHolder): ?>
        </div>
    </main>
<?php elseif (!empty($memberShowPublicChrome)): ?>
        </div>
    </main>
    <footer class="member-public-footer">
        <p><a href="/tickets">Buy match tickets</a> &middot; <a href="/season-tickets">Season tickets</a> &middot; <a href="/playersponsors">Sponsor a player</a></p>
        <p class="mt-2">Season ticket holders also get a digital ticket, announcements and Player of the Match voting. <a href="/members/register.php">Create a free account</a> or <a href="/members/login.php">log in</a>.</p>
    </footer>
<?php else: ?>
    </div>
<?php endif; ?>

<?php if ($currentHolder && FEEDBACK_BAR_ENABLED): ?>
<style>
.feedback-bar { position: fixed; left: 0; right: 0; bottom: 0; background: #4b0818; color: #fff; padding: 12px 16px; z-index: 1050; box-shadow: 0 -2px 10px rgba(0,0,0,.15); }
.feedback-bar .feedback-widget-inner { max-width: 640px; margin: 0 auto; }
.feedback-stars { display: inline-flex; flex-direction: row-reverse; }
.feedback-star { background: none; border: none; font-size: 1.6rem; line-height: 1; color: rgba(255,255,255,.4); cursor: pointer; padding: 0 2px; }
.feedback-star.is-filled, .feedback-star:hover, .feedback-star:hover ~ .feedback-star { color: #ffc107; }
.feedback-modal .feedback-star { color: #ccc; font-size: 2rem; }
.feedback-modal .feedback-star.is-filled { color: #ffc107; }
.feedback-fab { position: fixed; right: 20px; bottom: 20px; z-index: 1050; border-radius: 999px; padding: 10px 18px; background: #4b0818; color: #fff; border: none; box-shadow: 0 2px 10px rgba(0,0,0,.25); }
.feedback-close { background: none; border: none; color: rgba(255,255,255,.7); font-size: 1.2rem; line-height: 1; }
@media (max-width: 575.98px) {
    .feedback-bar { bottom:5.6rem; padding: 14px 16px 16px; }
    .feedback-fab { bottom:6.15rem; }
    .feedback-bar .feedback-widget-inner { position: relative; padding-right: 3.25rem; }
    .feedback-close {
        position: absolute;
        top: 0;
        right: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2.75rem;
        height: 2.75rem;
        font-size: 2rem;
        color: #fff;
    }
    .feedback-stars { gap: .15rem; }
    .feedback-star { font-size: 2.25rem; padding: 0 .12rem; }
}
</style>

<?php if (!$hasSubmittedFeedback): ?>
<div class="feedback-bar" id="feedbackBar">
    <div class="feedback-widget-inner d-flex flex-wrap align-items-center gap-3">
        <div class="flex-grow-1">
            <div class="fw-semibold small mb-1">How are we doing? Rate your experience of the members area.</div>
            <div class="feedback-widget" data-target="bar">
                <div class="feedback-stars mb-2" role="radiogroup" aria-label="Rating">
                    <?php for ($i = 5; $i >= 1; $i--): ?><button type="button" class="feedback-star" data-value="<?= $i ?>" aria-label="<?= $i ?> star<?= $i === 1 ? '' : 's' ?>">&#9733;</button><?php endfor; ?>
                </div>
                <div class="feedback-comment-wrap d-none">
                    <textarea class="form-control form-control-sm mb-2 feedback-comment" rows="2" placeholder="What's good, what's bad, how can we improve, what would you like to see?"></textarea>
                    <button type="button" class="btn btn-sm btn-light feedback-submit">Send feedback</button>
                </div>
                <div class="feedback-status small mt-1"></div>
            </div>
        </div>
        <button type="button" class="feedback-close" id="feedbackBarClose" aria-label="Close">&times;</button>
    </div>
</div>
<?php else: ?>
<button type="button" class="feedback-fab" id="feedbackFabOpen"><i class="fa-solid fa-comment-dots me-1" aria-hidden="true"></i>Feedback</button>
<?php endif; ?>

<div class="modal fade" id="feedbackModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Share your feedback</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <div class="feedback-widget feedback-modal" data-target="modal">
                    <div class="feedback-stars mb-2" role="radiogroup" aria-label="Rating">
                        <?php for ($i = 5; $i >= 1; $i--): ?><button type="button" class="feedback-star" data-value="<?= $i ?>" aria-label="<?= $i ?> star<?= $i === 1 ? '' : 's' ?>">&#9733;</button><?php endfor; ?>
                    </div>
                    <div class="feedback-comment-wrap d-none">
                        <textarea class="form-control mb-2 feedback-comment" rows="3" placeholder="What's good, what's bad, how can we improve, what would you like to see?"></textarea>
                        <button type="button" class="btn btn-brand feedback-submit">Send feedback</button>
                    </div>
                    <div class="feedback-status small mt-2"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>(() => {
    const csrfToken = <?= json_encode($feedbackCsrf) ?>;
    const page = <?= json_encode($feedbackPage) ?>;

    document.querySelectorAll('.feedback-widget').forEach((widget) => {
        let rating = 0;
        const stars = widget.querySelectorAll('.feedback-star');
        const commentWrap = widget.querySelector('.feedback-comment-wrap');
        const comment = widget.querySelector('.feedback-comment');
        const submitBtn = widget.querySelector('.feedback-submit');
        const status = widget.querySelector('.feedback-status');

        const paint = () => {
            stars.forEach((star) => {
                star.classList.toggle('is-filled', Number(star.dataset.value) <= rating);
            });
        };

        stars.forEach((star) => {
            star.addEventListener('click', () => {
                rating = Number(star.dataset.value);
                paint();
                commentWrap.classList.remove('d-none');
                status.textContent = '';
            });
        });

        submitBtn?.addEventListener('click', async () => {
            if (!rating) { return; }
            submitBtn.disabled = true;
            status.textContent = 'Sending…';
            try {
                const body = new URLSearchParams({ csrf_token: csrfToken, rating: String(rating), comment: comment.value, page });
                const response = await fetch('/members/feedback_submit.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body });
                const json = await response.json().catch(() => null);
                if (!response.ok || !json || !json.ok) { throw new Error((json && json.error) ? json.error : 'Could not send feedback.'); }
                status.textContent = 'Thanks for your feedback!';
                commentWrap.classList.add('d-none');
                submitBtn.disabled = false;

                // Once submitted, the footer bar never shows again — switch to the floating icon.
                document.getElementById('feedbackBar')?.remove();
                if (!document.getElementById('feedbackFabOpen')) {
                    const fab = document.createElement('button');
                    fab.type = 'button';
                    fab.className = 'feedback-fab';
                    fab.id = 'feedbackFabOpen';
                    fab.innerHTML = '<i class="fa-solid fa-comment-dots me-1" aria-hidden="true"></i>Feedback';
                    document.body.appendChild(fab);
                    fab.addEventListener('click', openModal);
                }
            } catch (error) {
                status.textContent = error.message || 'Could not send feedback.';
                submitBtn.disabled = false;
            }
        });
    });

    const openModal = () => {
        const modalEl = document.getElementById('feedbackModal');
        if (modalEl && window.bootstrap) {
            new window.bootstrap.Modal(modalEl).show();
        }
    };
    document.getElementById('feedbackFabOpen')?.addEventListener('click', openModal);
    document.getElementById('feedbackBarClose')?.addEventListener('click', () => {
        document.getElementById('feedbackBar')?.remove();
    });
})();</script>
<?php endif; ?>

</body>
</html>
