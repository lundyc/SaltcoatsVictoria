<?php
// Closes the page opened by header.php: wraps the captured body in the public-site
// chrome. (The disabled feedback widget that used to live here is kept, unused, in
// _feedback_widget.php.disabled — it was switched off via FEEDBACK_BAR_ENABLED.)
require_once __DIR__ . '/_shell.php';
member_shell_render((string) ob_get_clean());
