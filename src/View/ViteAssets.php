<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\View;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Manifest\ModuleResource;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBHTMLText;

/**
 * Emits <link>/<script> tags for a Vite entry from client/dist/.vite/manifest.json.
 * The built dist is committed with the module, so servers need no Node.js.
 *
 * Dev mode (HMR): set dev_server_url, e.g. "https://localhost:5173".
 */
class ViteAssets
{
    use Injectable;
    use Configurable;

    private const string MODULE = 'youwilllikeit/silverstripe-carehome';

    private static ?string $dev_server_url = null;

    /** @var array<string,mixed>|null */
    private ?array $manifest = null;

    public function tags(string $entry = 'client/src/portal.js'): DBHTMLText
    {
        $dev = static::config()->get('dev_server_url');
        if (is_string($dev) && $dev !== '') {
            $base = rtrim($dev, '/');
            $html = sprintf('<script type="module" src="%s/@vite/client"></script>', htmlspecialchars($base))
                . sprintf('<script type="module" src="%s/%s"></script>', htmlspecialchars($base), htmlspecialchars($entry));

            return DBField::create_field('HTMLFragment', $html);
        }

        $manifest = $this->manifest();
        $chunk = $manifest[$entry] ?? null;
        if (!is_array($chunk)) {
            return DBField::create_field('HTMLFragment', '<!-- carehome: vite entry ' . htmlspecialchars($entry) . ' missing, run npm run build -->');
        }

        $html = '';
        foreach ((array) ($chunk['css'] ?? []) as $css) {
            $html .= sprintf('<link rel="stylesheet" href="%s">', htmlspecialchars($this->url((string) $css)));
        }
        $html .= sprintf('<script type="module" src="%s"></script>', htmlspecialchars($this->url((string) $chunk['file'])));

        return DBField::create_field('HTMLFragment', $html);
    }

    private function url(string $distPath): string
    {
        return (string) ModuleResourceLoader::resourceURL(self::MODULE . ':client/dist/' . ltrim($distPath, '/'));
    }

    /** @return array<string,mixed> */
    private function manifest(): array
    {
        if ($this->manifest === null) {
            $resource = ModuleResourceLoader::singleton()->resolveResource(self::MODULE . ':client/dist/.vite/manifest.json');
            $file = $resource instanceof ModuleResource ? $resource->getPath() : '';
            $decoded = $file !== '' && is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            $this->manifest = is_array($decoded) ? $decoded : [];
        }

        return $this->manifest;
    }
}
