<?php

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/lib.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$currentUser = requireModule('minutes');

$id = ctype_digit((string) ($_GET['id'] ?? '')) ? (int) $_GET['id'] : 0;
$meeting = null;
try {
    $meeting = $id > 0 ? loadMeeting(databaseConnection(), $id) : null;
} catch (Throwable $exception) {
    $meeting = null;
}
if (!$meeting) {
    http_response_code(404);
    exit('Zápis nebyl nalezen.');
}

$autoload = __DIR__ . '/src/vendor/autoload.inc.php';
if (!is_file($autoload)) {
    http_response_code(500);
    exit('Chybí knihovna Dompdf (public/src/vendor). Nahrajte ji podle README.');
}
require $autoload;

$fontsDir = __DIR__ . '/fonts';
$variants = [
    ['normal', 'normal', 'font-regular.ttf'],
    ['bold', 'normal', 'font-bold.ttf'],
    ['normal', 'italic', 'font-italic.ttf'],
    ['bold', 'italic', 'font-bolditalic.ttf'],
];
$fontCss = '';
if (is_file($fontsDir . '/font-regular.ttf')) {
    foreach ($variants as [$weight, $style, $file]) {
        $path = $fontsDir . '/' . $file;
        if (is_file($path)) {
            $fontCss .= '@font-face { font-family: "SkautFont"; font-weight: ' . $weight . '; font-style: ' . $style
                . '; src: url("file://' . str_replace('\\', '/', $path) . '") format("truetype"); }' . "\n";
        }
    }
}

$workDir = sys_get_temp_dir() . '/strediskova-dompdf';
if (!is_dir($workDir)) {
    @mkdir($workDir, 0775, true);
}

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
$options->set('chroot', [__DIR__]);
$options->set('fontDir', $workDir);
$options->set('fontCache', $workDir);
$options->set('tempDir', $workDir);
$options->set('defaultFont', 'DejaVu Sans');

$pdfMode = true;
ob_start();
require __DIR__ . '/src/minutes_document.php';
$body = ob_get_clean();

$html = '<!DOCTYPE html><html lang="cs"><head><meta charset="utf-8"><style>'
    . $fontCss . file_get_contents(__DIR__ . '/src/pdf.css')
    . '</style></head><body>' . $body . '</body></html>';

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$canvas = $dompdf->getCanvas();
$font = $dompdf->getFontMetrics()->getFont('SkautFont', 'normal') ?: $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
$canvas->page_text(
    $canvas->get_width() - 80,
    $canvas->get_height() - 28,
    'Strana {PAGE_NUM}/{PAGE_COUNT}',
    $font,
    8,
    [0.35, 0.4, 0.37]
);

$filename = sprintf('zapis_%s_%s.pdf', preg_replace('~[^0-9]~', '_', $meeting['season']), str_pad((string) (int) $meeting['meeting_number'], 2, '0', STR_PAD_LEFT));
$dompdf->stream($filename, ['Attachment' => !isset($_GET['inline'])]);
