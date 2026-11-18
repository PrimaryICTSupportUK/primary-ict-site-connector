<?php
if (!defined('ABSPATH')) { exit; }

/** Capture only bounded categories; WordPress errors may contain credentials. */
final class PICTS_Upgrader_Diagnostic_Skin extends Automatic_Upgrader_Skin {
    private ?string $diagnostic = null;

    public static function classify($error): string {
        $code = is_wp_error($error) ? $error->get_error_code() : (is_string($error) ? $error : '');
        $categories = [
            'picts_package_intercepted' => 'package_intercepted',
            'picts_package_changed' => 'package_changed',
            'picts_package_host_unapproved' => 'package_host_unapproved',
            'picts_package_redirect' => 'package_redirect',
            'picts_package_denied' => 'package_denied',
            'picts_package_not_found' => 'package_not_found',
            'picts_package_download' => 'package_download_failed',
            'picts_package_too_large' => 'package_too_large',
            'picts_package_invalid' => 'package_invalid',
            'fs_unavailable' => 'filesystem_unavailable',
            'up_to_date' => 'already_current',
        ];
        return is_string($code) ? ($categories[$code] ?? 'other') : 'other';
    }

    public function error($error) {
        $value = self::classify($error);
        if ($this->diagnostic === null || $this->diagnostic === 'other') { $this->diagnostic = $value; }
        parent::error($error);
    }

    public function diagnostic(): ?string { return $this->diagnostic; }
}
