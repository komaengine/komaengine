<?php

namespace Database\Seeders;

use App\Models\Chapter;
use App\Models\Genre;
use App\Models\Page;
use App\Models\Series;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Seeds a believable manga library so every admin and theme screen has real
 * content on first run. Series titles are factual metadata; synopses are
 * original and covers/pages are generated tonal placeholders (no copyrighted
 * art), matching the approach documented in the mockup's manga-data.js.
 */
class SampleDataSeeder extends Seeder
{
    protected array $genres = [
        'Action', 'Adventure', 'Fantasy', 'Drama', 'Romance', 'Comedy',
        'Slice of Life', 'Horror', 'Sci-Fi', 'Sports', 'Mystery', 'Supernatural',
        'Seinen', 'Shonen', 'Shojo', 'Isekai', 'Psychological', 'Historical',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function series(): array
    {
        return [
            ['title' => "Frieren: Beyond Journey's End", 'author' => 'Kanehito Yamada', 'artist' => 'Tsukasa Abe', 'year' => 2020, 'status' => 'ongoing', 'dir' => 'rtl', 'hue' => 168, 'rating' => 'safe', 'genres' => ['Fantasy', 'Adventure', 'Drama', 'Shonen'], 'desc' => 'An elf mage who outlives her companions sets out to understand the people she traveled with, one quiet memory at a time.'],
            ['title' => 'Chainsaw Man', 'author' => 'Tatsuki Fujimoto', 'artist' => 'Tatsuki Fujimoto', 'year' => 2018, 'status' => 'ongoing', 'dir' => 'rtl', 'hue' => 18, 'rating' => 'mature', 'genres' => ['Action', 'Horror', 'Supernatural', 'Shonen'], 'desc' => 'A broke devil-hunter fuses with his chainsaw dog and is dragged into a brutal world of contracts, hunger, and impossible loyalty.'],
            ['title' => 'Vinland Saga', 'author' => 'Makoto Yukimura', 'artist' => 'Makoto Yukimura', 'year' => 2005, 'status' => 'ongoing', 'dir' => 'rtl', 'hue' => 210, 'rating' => 'mature', 'genres' => ['Action', 'Adventure', 'Drama', 'Historical', 'Seinen'], 'desc' => 'A boy raised on vengeance among Viking raiders slowly learns what it costs, and what it might mean, to put down the sword.'],
            ['title' => 'Dandadan', 'author' => 'Yukinobu Tatsu', 'artist' => 'Yukinobu Tatsu', 'year' => 2021, 'status' => 'ongoing', 'dir' => 'rtl', 'hue' => 286, 'rating' => 'mature', 'genres' => ['Action', 'Comedy', 'Supernatural', 'Sci-Fi', 'Shonen'], 'desc' => 'A girl who believes in ghosts and a boy who believes in aliens both turn out to be right, and now neither can get a normal day.'],
            ['title' => 'Oshi no Ko', 'author' => 'Aka Akasaka', 'artist' => 'Mengo Yokoyari', 'year' => 2020, 'status' => 'completed', 'dir' => 'rtl', 'hue' => 330, 'rating' => 'mature', 'genres' => ['Drama', 'Mystery', 'Psychological', 'Supernatural', 'Seinen'], 'desc' => 'Reborn as the children of their favorite idol, two siblings chase a secret hidden behind the bright lights of show business.'],
            ['title' => 'Blue Lock', 'author' => 'Muneyuki Kaneshiro', 'artist' => 'Yusuke Nomura', 'year' => 2018, 'status' => 'ongoing', 'dir' => 'rtl', 'hue' => 232, 'rating' => 'safe', 'genres' => ['Sports', 'Drama', 'Psychological', 'Shonen'], 'desc' => 'Three hundred strikers are locked in a facility built to forge the single most selfish goal-scorer Japan has ever produced.'],
            ['title' => 'Jujutsu Kaisen', 'author' => 'Gege Akutami', 'artist' => 'Gege Akutami', 'year' => 2018, 'status' => 'completed', 'dir' => 'rtl', 'hue' => 268, 'rating' => 'mature', 'genres' => ['Action', 'Supernatural', 'Horror', 'Shonen'], 'desc' => 'A student swallows a cursed relic to save his friends and is enrolled in a school that trains sorcerers to exorcise what he has become.'],
            ['title' => 'Spy x Family', 'author' => 'Tatsuya Endo', 'artist' => 'Tatsuya Endo', 'year' => 2019, 'status' => 'ongoing', 'dir' => 'rtl', 'hue' => 8, 'rating' => 'safe', 'genres' => ['Action', 'Comedy', 'Slice of Life', 'Shonen'], 'desc' => 'A spy, an assassin, and a telepath assemble a fake family for a mission, and accidentally build a real one.'],
            ['title' => 'Berserk', 'author' => 'Kentaro Miura', 'artist' => 'Kentaro Miura', 'year' => 1989, 'status' => 'hiatus', 'dir' => 'rtl', 'hue' => 0, 'rating' => 'mature', 'genres' => ['Action', 'Fantasy', 'Horror', 'Drama', 'Seinen'], 'desc' => 'A branded swordsman drags an impossibly heavy blade across a cursed world, hunted nightly by the demons his fate keeps summoning.'],
            ['title' => 'Solo Leveling', 'author' => 'Chugong', 'artist' => 'DUBU (REDICE)', 'year' => 2018, 'status' => 'completed', 'dir' => 'vertical', 'hue' => 248, 'rating' => 'safe', 'genres' => ['Action', 'Fantasy', 'Adventure', 'Supernatural'], 'desc' => 'The world\'s weakest hunter unlocks a private leveling system that turns every near-death dungeon run into raw, terrifying power.'],
            ['title' => 'Skip Beat!', 'author' => 'Yoshiki Nakamura', 'artist' => 'Yoshiki Nakamura', 'year' => 2002, 'status' => 'ongoing', 'dir' => 'rtl', 'hue' => 340, 'rating' => 'safe', 'genres' => ['Romance', 'Comedy', 'Drama', 'Shojo'], 'desc' => 'Dumped and used by her childhood crush, a girl storms into showbiz to out-shine him, and discovers she is frighteningly good at it.'],
            ['title' => 'Witch Hat Atelier', 'author' => 'Kamome Shirahama', 'artist' => 'Kamome Shirahama', 'year' => 2016, 'status' => 'ongoing', 'dir' => 'rtl', 'hue' => 152, 'rating' => 'safe', 'genres' => ['Fantasy', 'Adventure', 'Drama', 'Shonen'], 'desc' => 'A girl learns that magic is not a gift you are born with but a craft you draw, and that every spell is a line someone chose to make.'],
        ];
    }

    protected array $chapterTitles = [
        'The Weight of Morning', 'Ashes and Errands', 'A Quiet Contract',
        'What the River Kept', 'Names We Don\'t Say', 'The Long Way Down',
        'Borrowed Light', 'Every Door but One', 'The Smaller War',
        'Hands Full of Salt', 'After the Applause', 'The Second Promise',
    ];

    protected array $groups = ['Koma Scans', 'Crimson TL', 'Aozora Group', 'Official'];

    public function run(): void
    {
        Storage::disk('media')->makeDirectory('pages');

        $genres = [];
        foreach ($this->genres as $name) {
            $genres[$name] = Genre::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name],
            );
        }

        foreach ($this->series() as $data) {
            $series = Series::updateOrCreate(
                ['slug' => Str::slug($data['title'])],
                [
                    'title' => $data['title'],
                    'author' => $data['author'],
                    'artist' => $data['artist'],
                    'year' => $data['year'],
                    'status' => $data['status'],
                    'reading_direction' => $data['dir'],
                    'content_rating' => $data['rating'],
                    'cover_hue' => $data['hue'],
                    'synopsis' => $data['desc'],
                    'views' => random_int(20_000, 900_000),
                ],
            );

            $series->genres()->sync(
                collect($data['genres'])->map(fn ($g) => $genres[$g]->id)->all(),
            );

            $this->seedChapters($series);
        }
    }

    protected function seedChapters(Series $series): void
    {
        if ($series->chapters()->exists()) {
            return; // already seeded
        }

        $count = random_int(3, 5);

        for ($i = $count; $i >= 1; $i--) {
            $daysAgo = ($count - $i) * 6 + random_int(0, 4);

            $chapter = $series->chapters()->create([
                'number' => $i,
                'title' => $this->chapterTitles[($i * 5) % count($this->chapterTitles)],
                'scanlation_group' => $this->groups[$i % count($this->groups)],
                'status' => 'published',
                'published_at' => now()->subDays($daysAgo),
                'views' => random_int(5_000, 200_000),
            ]);

            $this->seedPages($series, $chapter);
        }
    }

    protected function seedPages(Series $series, Chapter $chapter): void
    {
        $pageCount = random_int(6, 9);

        for ($n = 1; $n <= $pageCount; $n++) {
            $hue = ($series->cover_hue + $n * 11) % 360;
            $binary = $this->renderPage($hue, $n, $series->monogram());
            $path = "pages/{$series->id}/{$chapter->id}/".Str::padLeft((string) $n, 3, '0').'.webp';

            Storage::disk('media')->put($path, $binary);

            Page::create([
                'chapter_id' => $chapter->id,
                'sort_order' => $n,
                'image_path' => $path,
                'width' => 800,
                'height' => 1200,
                'file_size' => strlen($binary),
            ]);
        }
    }

    /**
     * Render a tonal placeholder page (vertical gradient + big page number +
     * monogram watermark) and return the WEBP binary.
     */
    protected function renderPage(int $hue, int $pageNum, string $mono): string
    {
        $w = 800;
        $h = 1200;
        $img = imagecreatetruecolor($w, $h);

        [$r1, $g1, $b1] = $this->hslToRgb($hue / 360, 0.32, 0.85);
        [$r2, $g2, $b2] = $this->hslToRgb($hue / 360, 0.28, 0.66);

        for ($y = 0; $y < $h; $y++) {
            $t = $y / $h;
            $col = imagecolorallocate(
                $img,
                (int) round($r1 + ($r2 - $r1) * $t),
                (int) round($g1 + ($g2 - $g1) * $t),
                (int) round($b1 + ($b2 - $b1) * $t),
            );
            imageline($img, 0, $y, $w, $y, $col);
        }

        // A couple of translucent "panel" blocks for flavour.
        $panel = imagecolorallocatealpha($img, 255, 255, 255, 105);
        imagefilledrectangle($img, 60, 90, $w - 60, 430, $panel);
        imagefilledrectangle($img, 60, 470, $w - 60, 760, $panel);

        $this->drawScaledText($img, (string) $pageNum, $w / 2, $h / 2 + 260, 14, [255, 255, 255], 60);
        $this->drawScaledText($img, $mono, 96, 130, 6, [30, 26, 24], 40);

        ob_start();
        imagewebp($img, null, 82);
        $binary = (string) ob_get_clean();
        imagedestroy($img);

        return $binary;
    }

    /**
     * Draw text centred at ($cx,$cy) by rendering the built-in font onto a small
     * canvas and scaling it up — avoids bundling a TTF font just for placeholders.
     *
     * @param  array{int,int,int}  $rgb
     */
    protected function drawScaledText(\GdImage $dest, string $text, float $cx, float $cy, int $scale, array $rgb, int $alpha = 0): void
    {
        $font = 5;
        $tw = imagefontwidth($font) * max(1, strlen($text));
        $th = imagefontheight($font);

        $small = imagecreatetruecolor($tw, $th);
        imagesavealpha($small, true);
        imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
        $color = imagecolorallocatealpha($small, $rgb[0], $rgb[1], $rgb[2], (int) round($alpha / 2));
        imagestring($small, $font, 0, 0, $text, $color);

        $dw = $tw * $scale;
        $dh = $th * $scale;
        imagealphablending($dest, true);
        imagecopyresampled(
            $dest, $small,
            (int) round($cx - $dw / 2), (int) round($cy - $dh / 2), 0, 0,
            $dw, $dh, $tw, $th,
        );
        imagedestroy($small);
    }

    /**
     * @return array{float,float,float} RGB 0-255
     */
    protected function hslToRgb(float $h, float $s, float $l): array
    {
        if ($s == 0.0) {
            $v = $l * 255;

            return [$v, $v, $v];
        }

        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;

        return [
            $this->hue2rgb($p, $q, $h + 1 / 3) * 255,
            $this->hue2rgb($p, $q, $h) * 255,
            $this->hue2rgb($p, $q, $h - 1 / 3) * 255,
        ];
    }

    protected function hue2rgb(float $p, float $q, float $t): float
    {
        if ($t < 0) {
            $t += 1;
        }
        if ($t > 1) {
            $t -= 1;
        }
        if ($t < 1 / 6) {
            return $p + ($q - $p) * 6 * $t;
        }
        if ($t < 1 / 2) {
            return $q;
        }
        if ($t < 2 / 3) {
            return $p + ($q - $p) * (2 / 3 - $t) * 6;
        }

        return $p;
    }
}
