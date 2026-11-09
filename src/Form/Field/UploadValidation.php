<?php

namespace Encore\Admin\Form\Field;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class UploadValidation
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'avif'];

    public static function validate(UploadedFile $file, string $name, bool $image): void
    {
        self::validateName($file->getClientOriginalName());
        self::validateName($name);

        if ($image) {
            $extensions = implode(',', self::IMAGE_EXTENSIONS);
            Validator::make(['upload' => $file], [
                'upload' => ['required', 'image', 'mimes:'.$extensions, 'extensions:'.$extensions],
            ])->validate();

            if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true)) {
                throw ValidationException::withMessages(['upload' => 'Invalid image filename extension.']);
            }
        }
    }

    private static function validateName(string $name): void
    {
        if (
            $name === ''
            || $name !== trim($name, " \t\n\r\0\x0B.")
            || str_starts_with($name, '.')
            || preg_match('/[\\\\\/\x00-\x1f\x7f:]/', $name)
            || preg_match('/(?:^|\.)(?:php[0-9]*|phtml|pht|phar|phps|php-s)(?:\.|$)/i', $name)
        ) {
            throw ValidationException::withMessages(['upload' => 'Unsafe upload filename.']);
        }
    }
}
