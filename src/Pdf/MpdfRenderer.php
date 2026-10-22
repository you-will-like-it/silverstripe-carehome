<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Pdf;

use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Path;
use SilverStripe\Core\TempFolder;

class MpdfRenderer implements PdfRendererInterface
{
    use Injectable;
    use Configurable;

    private static array $mpdf_config = [
        'mode' => 'utf-8',
        'format' => 'A4',
        'margin_left' => 15,
        'margin_right' => 15,
        'margin_top' => 30,
        'margin_bottom' => 20,
        'margin_header' => 8,
        'margin_footer' => 8,
        'default_font' => 'dejavusans',
    ];

    public function render(string $bodyHtml, string $headerHtml = '', string $footerHtml = '', string $title = ''): string
    {
        $config = (array) static::config()->get('mpdf_config');
        $config['tempDir'] = Path::join(TempFolder::getTempFolder(BASE_PATH), 'mpdf');

        $mpdf = new Mpdf($config);
        $mpdf->SetTitle($title);
        $mpdf->SetCreator('CareHome');
        if ($headerHtml !== '') {
            $mpdf->SetHTMLHeader($headerHtml);
        }
        if ($footerHtml !== '') {
            $mpdf->SetHTMLFooter($footerHtml);
        }
        $mpdf->WriteHTML($bodyHtml, HTMLParserMode::DEFAULT_MODE);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }
}
