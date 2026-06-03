<?php

/**
 * Configuration metadata for the annotations plugin.
 *
 * Describes each $conf key for the Configuration Manager. Labels live in
 * lang/<iso>/settings.php. No require_once here.
 */

$meta['color_open']      = ['string', '_pattern' => '/^#[0-9a-fA-F]{6}$/'];
$meta['color_resolved']  = ['string', '_pattern' => '/^#[0-9a-fA-F]{6}$/'];
$meta['embed_max_bytes'] = ['numeric', '_min' => 1024];
$meta['context_length']  = ['numeric', '_min' => 0, '_max' => 1000];
$meta['body_cap']        = ['numeric', '_min' => 100];
