<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Service;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

/**
 * Bed QR codes as inline SVG (no GD/Imagick needed, prints sharp at any size).
 */
class QrCodeService
{
    use Injectable;
    use Configurable;

    /**
     * Absolute base URL encoded into QR codes, e.g. "https://dom.local".
     * Null = Director::absoluteBaseURL() of the current request — set it explicitly when
     * the CMS is reached under a different host name than the phones use.
     */
    private static ?string $portal_base_url = null;

    public function bedURL(string $hash): string
    {
        $base = static::config()->get('portal_base_url');
        $path = 'portal/bed/' . rawurlencode($hash);

        if (is_string($base) && $base !== '') {
            return rtrim($base, '/') . '/' . $path;
        }

        return (string) Director::absoluteURL($path);
    }

    public function svg(string $data): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'quietzoneSize' => 2,
        ]);

        return (new QRCode($options))->render($data);
    }
}
