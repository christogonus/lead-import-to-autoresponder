<?php

namespace App\View\Components\Marketing;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * A marketing photograph from config('marketing.photos').
 *
 * Served from public/images/marketing once `marketing:fetch-photos` has copied
 * it there, so visitors only ever talk to our own server. Until then it falls
 * back to the Pexels CDN, which resizes from query parameters.
 */
class Photo extends Component
{
    /** @var array{id?: int, src?: string, alt?: string, credit?: string|null} */
    public array $photo;

    public function __construct(
        public string $name,
        public string $ratio = 'aspect-4/3',
        public bool $eager = false,
        public string $sizes = '(min-width: 1024px) 50vw, 100vw',
    ) {
        $this->photo = config('marketing.photos.'.$name, []);
    }

    /**
     * The image's fallback URL, used where srcset is not supported.
     */
    public function src(): ?string
    {
        if (isset($this->photo['src'])) {
            return asset($this->photo['src']);
        }

        if (! isset($this->photo['id'])) {
            return null;
        }

        return $this->urlFor(1024);
    }

    /**
     * Every available width, for the browser to choose from.
     */
    public function srcset(): ?string
    {
        if (isset($this->photo['src']) || ! isset($this->photo['id'])) {
            return null;
        }

        return collect(config('marketing.photo_widths'))
            ->map(fn (int $width): string => $this->urlFor($width).' '.$width.'w')
            ->implode(', ');
    }

    public function alt(): string
    {
        return $this->photo['alt'] ?? '';
    }

    /**
     * Where a downloaded copy of a photo lives, relative to public/.
     */
    public static function localPath(string $name, int $width): string
    {
        return 'images/marketing/'.$name.'-'.$width.'.jpg';
    }

    /**
     * The Pexels CDN address of a photo at the given width.
     */
    public static function remoteUrl(int $id, int $width): string
    {
        return 'https://images.pexels.com/photos/'.$id.'/pexels-photo-'.$id.'.jpeg'
            .'?auto=compress&cs=tinysrgb&fit=crop&w='.$width;
    }

    private function urlFor(int $width): string
    {
        $local = static::localPath($this->name, $width);

        return is_file(public_path($local))
            ? asset($local)
            : static::remoteUrl($this->photo['id'], $width);
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('components.marketing.photo');
    }
}
