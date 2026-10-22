<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Pdf;

/**
 * PDF backend abstraction. Default binding: MpdfRenderer.
 * To use kalakotra/silverstripe-pdf, write a thin adapter implementing this interface and bind it:
 *
 * SilverStripe\Core\Injector\Injector:
 *   YouWillLikeIT\CareHome\Pdf\PdfRendererInterface:
 *     class: App\Pdf\KalakotraCareHomeRenderer
 */
interface PdfRendererInterface
{
    /**
     * @param string $bodyHtml   main content
     * @param string $headerHtml repeated on every page
     * @param string $footerHtml repeated on every page ({PAGENO}/{nbpg} placeholders supported by mPDF)
     * @return string binary PDF
     */
    public function render(string $bodyHtml, string $headerHtml = '', string $footerHtml = '', string $title = ''): string;
}
