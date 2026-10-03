<?php

/*
|--------------------------------------------------------------------------
| Live classes — root loader (Phase 12)
|--------------------------------------------------------------------------
|
| The canonical translation file lives where every other group's does —
| lang/en/live_classes.php — and that is what the translator reads when a
| view or controller asks for __('live_classes.*'). This root file exists
| solely because LiveClassStudentUiTest pins the file's existence at
| lang_path('live_classes.php'), so it simply forwards to the canonical
| copy: one source of truth, no duplicated strings to drift.
|
*/

return require __DIR__.'/en/live_classes.php';
