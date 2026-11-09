<?php

namespace Encore\Admin\Tests\Compatibility;

use Encore\Admin\Form\Field\UploadValidation;
use Encore\Admin\Form\Field\File;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class UploadValidationTest extends TestCase
{
    private mixed $previousApplication;
    private string $file;

    protected function setUp(): void
    {
        $this->previousApplication = Validator::getFacadeApplication();
        $application = new Container();
        $application->instance('validator', new Factory(new Translator(new ArrayLoader(), 'en'), $application));
        Validator::clearResolvedInstance('validator');
        Validator::setFacadeApplication($application);
        $this->file = tempnam(sys_get_temp_dir(), 'encore-upload-');
        file_put_contents($this->file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII='));
    }

    protected function tearDown(): void
    {
        unlink($this->file);
        Validator::clearResolvedInstance('validator');
        Validator::setFacadeApplication($this->previousApplication);
    }

    public static function unsafeNames(): array
    {
        return array_map(fn (string $name) => [$name], [
            'avatar.php', 'avatar.PHP', 'avatar.php8', 'avatar.php.jpg', 'avatar.phtml',
            'avatar.pht', 'avatar.phar', '.htaccess', '.user.ini', '../avatar.png',
            'folder/avatar.png', 'folder\\avatar.png', 'avatar.php.', 'avatar.png ', '../avatar.php', 'folder/avatar.php', 'folder\\avatar.php',
        ]);
    }

    public static function unsafeClientNames(): array
    {
        return array_filter(self::unsafeNames(), fn (array $case) => !in_array($case[0], [
            '../avatar.png', 'folder/avatar.png', 'folder\\avatar.png',
        ], true));
    }

    #[DataProvider('unsafeClientNames')]
    public function testUnsafeClientNamesAreRejectedEvenForValidImageContent(string $name): void
    {
        $this->expectException(ValidationException::class);
        UploadValidation::validate(new UploadedFile($this->file, $name, null, null, true), 'safe.png', true);
    }

    public static function unsafeDestinations(): array
    {
        $cases = [];
        foreach ([UploadProbe::class, ImageUploadProbe::class, MultipleUploadProbe::class, MultipleImageUploadProbe::class] as $class) {
            foreach (self::unsafeNames() as [$name]) {
                $cases[] = [$class, $name];
            }
        }
        return $cases;
    }

    #[DataProvider('unsafeDestinations')]
    public function testUnsafeGeneratedNamesAreRejectedBeforeStorage(string $class, string $name): void
    {
        $field = new $class($name);
        try {
            $field->persist(new UploadedFile($this->file, 'safe.png', null, null, true));
            $this->fail('Unsafe filename was accepted.');
        } catch (ValidationException) {
            $this->assertSame(0, $field->writes);
        }
    }

    public function testSymfonyNormalizesClientPathBeforeValidation(): void
    {
        foreach (['../avatar.png', 'folder/avatar.png', 'folder\\avatar.png'] as $name) {
            $file = new UploadedFile($this->file, $name, null, null, true);
            $this->assertSame('avatar.png', $file->getClientOriginalName());
            UploadValidation::validate($file, 'safe.png', true);
        }
    }

    public function testValidImageAndOrdinaryDocumentsAreAccepted(): void
    {
        UploadValidation::validate(new UploadedFile($this->file, 'avatar.png', null, null, true), 'renamed.png', true);
        $field = new UploadProbe('report.txt');
        $this->assertSame('uploads/report.txt', $field->persist(new UploadedFile($this->file, 'report.txt', null, null, true)));
        $this->assertSame(1, $field->writes);
    }

    public function testContentMasqueradingAsImageIsRejected(): void
    {
        file_put_contents($this->file, 'This is not an image.');
        $this->expectException(ValidationException::class);
        UploadValidation::validate(new UploadedFile($this->file, 'avatar.png', 'image/png', null, true), 'avatar.png', true);
    }

    public function testValidImageCannotBeStoredUsingNonImageExtension(): void
    {
        $this->expectException(ValidationException::class);
        UploadValidation::validate(new UploadedFile($this->file, 'avatar.png', null, null, true), 'avatar.txt', true);
    }
}

trait UploadProbeState
{
    public int $writes = 0;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->directory = 'uploads';
        $this->storage = new class($this) {
            public function __construct(private object $field) {}
            public function exists($path) { return false; }
            public function putFileAs($directory, $file, $name) { $this->field->writes++; return $directory.'/'.$name; }
        };
    }

}

class UploadProbe extends File
{
    use UploadProbeState;

    public function persist(UploadedFile $file): mixed
    {
        return $this->uploadAndDeleteOriginal($file);
    }
}

class ImageUploadProbe extends \Encore\Admin\Form\Field\Image
{
    use UploadProbeState;

    public function persist(UploadedFile $file): mixed
    {
        return $this->uploadAndDeleteOriginal($file);
    }
}

class MultipleUploadProbe extends \Encore\Admin\Form\Field\MultipleFile
{
    use UploadProbeState;

    public function persist(UploadedFile $file): mixed
    {
        return $this->upload($file);
    }
}

class MultipleImageUploadProbe extends \Encore\Admin\Form\Field\MultipleImage
{
    use UploadProbeState;

    public function persist(UploadedFile $file): mixed
    {
        return $this->upload($file);
    }
}
