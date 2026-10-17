<?php
/**
 * SVG QR generation for Local Money Map redirect URLs.
 *
 * @package MillionDollarScript\Extensions\LocalMoneyMap
 */

namespace MillionDollarScript\Extensions\LocalMoneyMap;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Exception\WriterException;

if (!defined('ABSPATH')) {
    exit;
}

final class QrCode {

    /**
     * Render a standards-compliant QR code as inline SVG.
     *
     * @return string|\WP_Error
     */
    public static function svg($text, $scale = 6, $border = 4, $title = '') {
        $matrix = self::matrix($text);
        if (is_wp_error($matrix)) {
            return $matrix;
        }

        $scale = max(1, min(20, absint($scale)));
        $border = max(0, min(12, absint($border)));
        $size = count($matrix);
        $dimension = ($size + ($border * 2)) * $scale;
        $view_size = $size + ($border * 2);
        $path = [];

        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $path[] = 'M' . ($x + $border) . ',' . ($y + $border) . 'h1v1h-1z';
                }
            }
        }

        $title_markup = '' !== trim((string) $title)
            ? '<title>' . esc_html($title) . '</title>'
            : '';

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . esc_attr((string) $dimension) . '" height="' . esc_attr((string) $dimension) . '" viewBox="0 0 ' . esc_attr((string) $view_size) . ' ' . esc_attr((string) $view_size) . '" role="img">' . $title_markup . '<rect width="100%" height="100%" fill="#fff"/><path fill="#111827" d="' . esc_attr(implode('', $path)) . '"/></svg>';
    }

    /**
     * Return the QR module matrix as rows of booleans.
     *
     * @return array<int,array<int,bool>>|\WP_Error
     */
    public static function matrix($text) {
        $text = (string) $text;
        if ('' === $text) {
            return new \WP_Error('mds_lmm_qr_empty', __('QR target cannot be empty.', 'mds-local-money-map'));
        }

        if (!class_exists(Encoder::class)) {
            return new \WP_Error(
                'mds_lmm_qr_dependency_missing',
                __('The QR generation library is unavailable. Reinstall Local Money Map and try again.', 'mds-local-money-map')
            );
        }

        try {
            $encoded = Encoder::encode($text, ErrorCorrectionLevel::L(), 'UTF-8');
            $source = $encoded->getMatrix();
            $matrix = [];

            for ($y = 0; $y < $source->getHeight(); $y++) {
                $row = [];
                for ($x = 0; $x < $source->getWidth(); $x++) {
                    $row[] = 1 === $source->get($x, $y);
                }
                $matrix[] = $row;
            }

            return $matrix;
        } catch (WriterException $exception) {
            return new \WP_Error(
                'mds_lmm_qr_too_long',
                __('QR target is too long to encode. Shorten the target URL and try again.', 'mds-local-money-map')
            );
        } catch (\Throwable $exception) {
            return new \WP_Error(
                'mds_lmm_qr_generation_failed',
                __('The QR code could not be generated. Check the target URL and try again.', 'mds-local-money-map')
            );
        }
    }
}
