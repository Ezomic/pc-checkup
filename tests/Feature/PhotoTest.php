<?php

declare(strict_types=1);

use App\Models\Machine;
use App\Models\Photo;
use App\Models\User;
use App\Services\Photos\PhotoProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

beforeEach(function () {
    Storage::fake('photos');
});

function jpegBytes(int $width = 40, int $height = 20): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, intdiv($width, 2), $height, 0xFF0000);

    ob_start();
    imagejpeg($image);

    return (string) ob_get_clean();
}

function jpegWithOrientation(int $orientation, int $width = 40, int $height = 20): string
{
    $jpeg = jpegBytes($width, $height);
    $tiff = "II\x2A\x00\x08\x00\x00\x00"."\x01\x00"."\x12\x01\x03\x00\x01\x00\x00\x00".pack('v', $orientation)."\x00\x00"."\x00\x00\x00\x00";
    $app1 = "\xFF\xE1".pack('n', 2 + 6 + strlen($tiff))."Exif\x00\x00".$tiff;

    return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
}

/**
 * @return array{int, int}
 */
function storedSize(Photo $photo): array
{
    $info = getimagesizefromstring(Storage::disk('photos')->get($photo->path) ?? '');

    return [$info[0] ?? 0, $info[1] ?? 0];
}

describe('PhotoProcessor', function () {
    it('scales a large image down so the longest edge is 1600 px', function () {
        $result = (new PhotoProcessor)->process(jpegBytes(3200, 1600));

        expect($result['width'])->toBe(1600)->and($result['height'])->toBe(800);
    });

    it('scales a tall image by its height', function () {
        $result = (new PhotoProcessor)->process(jpegBytes(1000, 2000));

        expect($result['width'])->toBe(800)->and($result['height'])->toBe(1600);
    });

    it('never scales a small image up', function () {
        $result = (new PhotoProcessor)->process(jpegBytes(400, 300));

        expect($result['width'])->toBe(400)->and($result['height'])->toBe(300);
    });

    it('re-encodes as jpeg', function () {
        $image = imagecreatetruecolor(50, 50);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $result = (new PhotoProcessor)->process($png);

        expect(getimagesizefromstring($result['bytes'])[2] ?? null)->toBe(IMAGETYPE_JPEG);
    });

    it('flattens transparency onto white instead of black', function () {
        $image = imagecreatetruecolor(10, 10);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $result = (new PhotoProcessor)->process($png);
        $decoded = imagecreatefromstring($result['bytes']);

        expect($decoded)->not->toBeFalse();
        $rgb = imagecolorat($decoded, 5, 5);
        expect(($rgb >> 16) & 0xFF)->toBeGreaterThan(240);
    });

    it('strips exif metadata', function () {
        $result = (new PhotoProcessor)->process(jpegWithOrientation(1));

        expect($result['bytes'])->not->toContain('Exif');
    });

    it('applies the exif orientation before dropping it', function (int $orientation, int $width, int $height) {
        $result = (new PhotoProcessor)->process(jpegWithOrientation($orientation, 40, 20));

        expect($result['width'])->toBe($width)->and($result['height'])->toBe($height);
    })->with([
        'upright' => [1, 40, 20],
        'upside down' => [3, 40, 20],
        'rotated clockwise' => [6, 20, 40],
        'rotated anticlockwise' => [8, 20, 40],
    ]);

    it('reads a jpeg that has no exif at all', function () {
        expect((new PhotoProcessor)->process(jpegBytes())['width'])->toBe(40);
    });

    it('rejects something that is not an image', function () {
        expect(fn () => (new PhotoProcessor)->process('plain text'))->toThrow(ValidationException::class);
    });

    it('rejects an image type it does not accept', function () {
        $image = imagecreatetruecolor(5, 5);
        ob_start();
        imagegif($image);
        $gif = (string) ob_get_clean();

        expect(fn () => (new PhotoProcessor)->process($gif))->toThrow(ValidationException::class);
    });

    it('rejects an image with an absurd pixel count before decoding it', function () {
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('N', 20000).pack('N', 20000)."\x08\x02\x00\x00\x00".pack('N', 0);

        expect(fn () => (new PhotoProcessor)->process($png))->toThrow(ValidationException::class);
    });

    it('rejects an image whose header is fine but whose data is corrupt', function () {
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('N', 100).pack('N', 100)."\x08\x02\x00\x00\x00".pack('N', 0).'garbage';

        expect(fn () => (new PhotoProcessor)->process($png))->toThrow(ValidationException::class);
    });
});

describe('POST /machines/{machine}/photos', function () {
    it('stores a resized jpeg on the private disk', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();

        actingAs($user)->post("/machines/{$machine->id}/photos", [
            'photo' => UploadedFile::fake()->image('dent.jpg', 3000, 2000),
            'caption' => 'Dent near the hinge',
        ])->assertRedirect("/machines/{$machine->id}");

        $photo = Photo::query()->sole();
        expect($photo->machine_id)->toBe($machine->id)
            ->and($photo->caption)->toBe('Dent near the hinge')
            ->and($photo->width)->toBe(1600)
            ->and($photo->height)->toBe(1067)
            ->and($photo->path)->toStartWith("{$machine->id}/")->toEndWith('.jpg')
            ->and(storedSize($photo))->toBe([1600, 1067]);
        Storage::disk('photos')->assertExists($photo->path);
    });

    it('never keeps the uploaded file name', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();

        actingAs($user)->post("/machines/{$machine->id}/photos", ['photo' => UploadedFile::fake()->image('../../etc/passwd.jpg')]);

        expect(Photo::query()->sole()->path)->not->toContain('passwd')->not->toContain('..');
    });

    it('accepts png and webp', function (string $name) {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();

        actingAs($user)->post("/machines/{$machine->id}/photos", ['photo' => UploadedFile::fake()->image($name, 100, 100)])
            ->assertSessionHasNoErrors();

        assertDatabaseCount('photos', 1);
    })->with(['a.png', 'a.webp']);

    it('stores a blank caption as no caption', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();

        actingAs($user)->post("/machines/{$machine->id}/photos", ['photo' => UploadedFile::fake()->image('a.jpg'), 'caption' => '   ']);

        expect(Photo::query()->sole()->caption)->toBeNull();
    });

    it('rejects a caption over 255 characters', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();

        actingAs($user)->post("/machines/{$machine->id}/photos", ['photo' => UploadedFile::fake()->image('a.jpg'), 'caption' => str_repeat('a', 256)])
            ->assertSessionHasErrors('caption');
    });

    it('requires a photo', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();

        actingAs($user)->post("/machines/{$machine->id}/photos", [])->assertSessionHasErrors('photo');
    });

    it('rejects files that are not photos', function (string $name, string $content) {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();

        actingAs($user)->post("/machines/{$machine->id}/photos", ['photo' => UploadedFile::fake()->createWithContent($name, $content)])
            ->assertSessionHasErrors('photo');

        assertDatabaseCount('photos', 0);
    })->with([
        'text named jpg' => ['x.jpg', 'not an image'],
        'svg' => ['x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
        'php' => ['x.php', '<?php echo 1;'],
        'html' => ['x.html', '<html></html>'],
    ]);

    it('rejects a photo over 10 MB', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();

        actingAs($user)->post("/machines/{$machine->id}/photos", ['photo' => UploadedFile::fake()->create('big.jpg', 10241, 'image/jpeg')])
            ->assertSessionHasErrors('photo');
    });

    it('reports a corrupt image on the form instead of failing', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('N', 100).pack('N', 100)."\x08\x02\x00\x00\x00".pack('N', 0).'garbage';

        actingAs($user)->post("/machines/{$machine->id}/photos", ['photo' => UploadedFile::fake()->createWithContent('bad.png', $png)])
            ->assertSessionHasErrors('photo');

        assertDatabaseCount('photos', 0);
    });

    it('allows thirty photos per machine and no more', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();
        Photo::factory()->count(29)->for($machine)->create();

        actingAs($user)->post("/machines/{$machine->id}/photos", ['photo' => UploadedFile::fake()->image('a.jpg')])
            ->assertSessionHasNoErrors();
        actingAs($user)->post("/machines/{$machine->id}/photos", ['photo' => UploadedFile::fake()->image('b.jpg')])
            ->assertSessionHasErrors('photo');

        assertDatabaseCount('photos', 30);
    });

    it('counts the limit per machine', function () {
        $user = User::factory()->create();
        $full = Machine::factory()->for($user)->create();
        $empty = Machine::factory()->for($user)->create();
        Photo::factory()->count(30)->for($full)->create();

        actingAs($user)->post("/machines/{$empty->id}/photos", ['photo' => UploadedFile::fake()->image('a.jpg')])
            ->assertSessionHasNoErrors();
    });

    it('forbids another user’s machine', function () {
        $machine = Machine::factory()->create();

        actingAs(User::factory()->create())->post("/machines/{$machine->id}/photos", ['photo' => UploadedFile::fake()->image('a.jpg')])
            ->assertForbidden();

        assertDatabaseCount('photos', 0);
    });

    it('requires authentication', function () {
        $machine = Machine::factory()->create();

        $this->post("/machines/{$machine->id}/photos", [])->assertRedirect('/login');
    });
});

describe('GET /machines/{machine}/photos/{photo}', function () {
    it('serves the photo to its owner as a private jpeg', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();
        Storage::disk('photos')->put('1/a.jpg', jpegBytes());
        $photo = Photo::factory()->for($machine)->create(['path' => '1/a.jpg']);

        $response = actingAs($user)->get("/machines/{$machine->id}/photos/{$photo->id}")->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('image/jpeg')
            ->and($response->headers->get('Cache-Control'))->toContain('private')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    });

    it('forbids another user', function () {
        $machine = Machine::factory()->create();
        Storage::disk('photos')->put('1/a.jpg', jpegBytes());
        $photo = Photo::factory()->for($machine)->create(['path' => '1/a.jpg']);

        actingAs(User::factory()->create())->get("/machines/{$machine->id}/photos/{$photo->id}")->assertForbidden();
    });

    it('does not serve a photo through a different machine', function () {
        $user = User::factory()->create();
        $mine = Machine::factory()->for($user)->create();
        $other = Machine::factory()->for($user)->create();
        Storage::disk('photos')->put('1/a.jpg', jpegBytes());
        $photo = Photo::factory()->for($other)->create(['path' => '1/a.jpg']);

        actingAs($user)->get("/machines/{$mine->id}/photos/{$photo->id}")->assertNotFound();
    });

    it('answers not found when the file is gone', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();
        $photo = Photo::factory()->for($machine)->create(['path' => '1/missing.jpg']);

        actingAs($user)->get("/machines/{$machine->id}/photos/{$photo->id}")->assertNotFound();
    });

    it('requires authentication', function () {
        $machine = Machine::factory()->create();
        $photo = Photo::factory()->for($machine)->create();

        $this->get("/machines/{$machine->id}/photos/{$photo->id}")->assertRedirect('/login');
    });
});

describe('DELETE /machines/{machine}/photos/{photo}', function () {
    it('removes the row and the file', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();
        Storage::disk('photos')->put('1/a.jpg', jpegBytes());
        $photo = Photo::factory()->for($machine)->create(['path' => '1/a.jpg']);

        actingAs($user)->delete("/machines/{$machine->id}/photos/{$photo->id}")->assertRedirect("/machines/{$machine->id}");

        assertDatabaseMissing('photos', ['id' => $photo->id]);
        Storage::disk('photos')->assertMissing('1/a.jpg');
    });

    it('forbids another user and keeps the file', function () {
        $machine = Machine::factory()->create();
        Storage::disk('photos')->put('1/a.jpg', jpegBytes());
        $photo = Photo::factory()->for($machine)->create(['path' => '1/a.jpg']);

        actingAs(User::factory()->create())->delete("/machines/{$machine->id}/photos/{$photo->id}")->assertForbidden();

        assertDatabaseHas('photos', ['id' => $photo->id]);
        Storage::disk('photos')->assertExists('1/a.jpg');
    });

    it('cannot delete a photo through a different machine of the same user', function () {
        $user = User::factory()->create();
        $mine = Machine::factory()->for($user)->create();
        $other = Machine::factory()->for($user)->create();
        $photo = Photo::factory()->for($other)->create();

        actingAs($user)->delete("/machines/{$mine->id}/photos/{$photo->id}")->assertNotFound();

        assertDatabaseHas('photos', ['id' => $photo->id]);
    });
});

it('lists photos on the machine page with a private url', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create();
    $photo = Photo::factory()->for($machine)->create(['caption' => 'Screen', 'width' => 800, 'height' => 600]);
    Photo::factory()->create();

    actingAs($user)->get("/machines/{$machine->id}")
        ->assertInertia(fn ($page) => $page
            ->has('photos', 1)
            ->where('photos.0.id', $photo->id)
            ->where('photos.0.caption', 'Screen')
            ->where('photos.0.width', 800)
            ->where('photos.0.url', "/machines/{$machine->id}/photos/{$photo->id}")
            ->missing('photos.0.path'));
});
