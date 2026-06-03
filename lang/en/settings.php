<?php

/**
 * English configuration-manager labels for the annotations plugin.
 */

$lang['color_open']      = 'Highlight colour for open (unresolved) annotations. Hex value, e.g. #f59e0b. Lighter fill and pill tints are derived from it automatically.';
$lang['color_resolved']  = 'Highlight colour for resolved annotations. Hex value, e.g. #4ade80.';
$lang['embed_max_bytes'] = 'Maximum size (bytes) of the annotation list shipped inline with the page. Larger lists are fetched via a separate AJAX request instead, keeping every page view lean.';
$lang['context_length']  = 'Number of characters of surrounding text stored on each side of a quote to re-locate it later (and disambiguate repeated quotes). 0 disables context.';
$lang['body_cap']        = 'Maximum length (characters) of an annotation or reply body. Longer input is truncated.';
