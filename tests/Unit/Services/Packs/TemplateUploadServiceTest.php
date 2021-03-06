<?php
/**
 * Pterodactyl - Panel
 * Copyright (c) 2015 - 2017 Dane Everitt <dane@daneeveritt.com>.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace Tests\Unit\Services\Packs;

use ZipArchive;
use Mockery as m;
use Tests\TestCase;
use Pterodactyl\Models\Pack;
use Illuminate\Http\UploadedFile;
use Pterodactyl\Services\Packs\PackCreationService;
use Pterodactyl\Services\Packs\TemplateUploadService;
use Pterodactyl\Exceptions\Service\Pack\ZipExtractionException;
use Pterodactyl\Exceptions\Service\Pack\InvalidFileUploadException;
use Pterodactyl\Exceptions\Service\Pack\InvalidFileMimeTypeException;
use Pterodactyl\Exceptions\Service\Pack\UnreadableZipArchiveException;
use Pterodactyl\Exceptions\Service\Pack\InvalidPackArchiveFormatException;

class TemplateUploadServiceTest extends TestCase
{
    const JSON_FILE_CONTENTS = '{"test_content": "value"}';

    /**
     * @var \ZipArchive
     */
    protected $archive;

    /**
     * @var \Pterodactyl\Services\Packs\PackCreationService
     */
    protected $creationService;

    /**
     * @var \Illuminate\Http\UploadedFile
     */
    protected $file;

    /**
     * @var \Pterodactyl\Services\Packs\TemplateUploadService
     */
    protected $service;

    /**
     * Setup tests.
     */
    public function setUp()
    {
        parent::setUp();

        $this->archive = m::mock(ZipArchive::class);
        $this->creationService = m::mock(PackCreationService::class);
        $this->file = m::mock(UploadedFile::class);

        $this->service = new TemplateUploadService($this->creationService, $this->archive);
    }

    /**
     * Test that a JSON file can be processed and turned into a pack.
     *
     * @dataProvider jsonMimetypeProvider
     */
    public function testJsonFileIsProcessed($mime)
    {
        $this->file->shouldReceive('isValid')->withNoArgs()->once()->andReturn(true);
        $this->file->shouldReceive('getMimeType')->withNoArgs()->twice()->andReturn($mime);
        $this->file->shouldReceive('getSize')->withNoArgs()->once()->andReturn(128);
        $this->file->shouldReceive('openFile')->withNoArgs()->once()->andReturnSelf()
            ->shouldReceive('fread')->with(128)->once()->andReturn(self::JSON_FILE_CONTENTS);

        $this->creationService->shouldReceive('handle')->with(['test_content' => 'value', 'option_id' => 1])
            ->once()->andReturn(factory(Pack::class)->make());

        $this->assertInstanceOf(Pack::class, $this->service->handle(1, $this->file));
    }

    /**
     * Test that a zip file can be processed.
     */
    public function testZipfileIsProcessed()
    {
        $model = factory(Pack::class)->make();

        $this->file->shouldReceive('isValid')->withNoArgs()->once()->andReturn(true);
        $this->file->shouldReceive('getMimeType')->withNoArgs()->twice()->andReturn('application/zip');

        $this->file->shouldReceive('getRealPath')->withNoArgs()->once()->andReturn('/test/real');
        $this->archive->shouldReceive('open')->with('/test/real')->once()->andReturn(true);
        $this->archive->shouldReceive('locateName')->with('import.json')->once()->andReturn(true);
        $this->archive->shouldReceive('locateName')->with('archive.tar.gz')->once()->andReturn(true);
        $this->archive->shouldReceive('getFromName')->with('import.json')->once()->andReturn(self::JSON_FILE_CONTENTS);
        $this->creationService->shouldReceive('handle')->with(['test_content' => 'value', 'option_id' => 1])
            ->once()->andReturn($model);
        $this->archive->shouldReceive('extractTo')->with(storage_path('app/packs/' . $model->uuid), 'archive.tar.gz')
            ->once()->andReturn(true);
        $this->archive->shouldReceive('close')->withNoArgs()->once()->andReturnNull();

        $this->assertInstanceOf(Pack::class, $this->service->handle(1, $this->file));
    }

    /**
     * Test that an exception is thrown if the file upload is invalid.
     */
    public function testExceptionIsThrownIfFileUploadIsInvalid()
    {
        $this->file->shouldReceive('isValid')->withNoArgs()->once()->andReturn(false);

        try {
            $this->service->handle(1, $this->file);
        } catch (InvalidFileUploadException $exception) {
            $this->assertEquals(trans('admin/exceptions.packs.invalid_upload'), $exception->getMessage());
        }
    }

    /**
     * Test that an invalid mimetype throws an exception.
     *
     * @dataProvider invalidMimetypeProvider
     */
    public function testExceptionIsThrownIfMimetypeIsInvalid($mime)
    {
        $this->file->shouldReceive('isValid')->withNoArgs()->once()->andReturn(true);
        $this->file->shouldReceive('getMimeType')->withNoArgs()->once()->andReturn($mime);

        try {
            $this->service->handle(1, $this->file);
        } catch (InvalidFileMimeTypeException $exception) {
            $this->assertEquals(trans('admin/exceptions.packs.invalid_mime', [
                'type' => implode(', ', TemplateUploadService::VALID_UPLOAD_TYPES),
            ]), $exception->getMessage());
        }
    }

    /**
     * Test that an exception is thrown if the zip is unreadable.
     */
    public function testExceptionIsThrownIfZipArchiveIsUnreadable()
    {
        $this->file->shouldReceive('isValid')->withNoArgs()->once()->andReturn(true);
        $this->file->shouldReceive('getMimeType')->withNoArgs()->twice()->andReturn('application/zip');

        $this->file->shouldReceive('getRealPath')->withNoArgs()->once()->andReturn('/test/path');
        $this->archive->shouldReceive('open')->with('/test/path')->once()->andReturn(false);

        try {
            $this->service->handle(1, $this->file);
        } catch (UnreadableZipArchiveException $exception) {
            $this->assertEquals(trans('admin/exceptions.packs.unreadable'), $exception->getMessage());
        }
    }

    /**
     * Test that a zip missing the required files throws an exception.
     *
     * @dataProvider filenameProvider
     */
    public function testExceptionIsThrownIfZipDoesNotContainProperFiles($a, $b)
    {
        $this->file->shouldReceive('isValid')->withNoArgs()->once()->andReturn(true);
        $this->file->shouldReceive('getMimeType')->withNoArgs()->twice()->andReturn('application/zip');

        $this->file->shouldReceive('getRealPath')->withNoArgs()->once()->andReturn('/test/path');
        $this->archive->shouldReceive('open')->with('/test/path')->once()->andReturn(true);
        $this->archive->shouldReceive('locateName')->with('import.json')->once()->andReturn($a);

        if ($a) {
            $this->archive->shouldReceive('locateName')->with('archive.tar.gz')->once()->andReturn($b);
        }

        try {
            $this->service->handle(1, $this->file);
        } catch (InvalidPackArchiveFormatException $exception) {
            $this->assertEquals(trans('admin/exceptions.packs.invalid_archive_exception'), $exception->getMessage());
        }
    }

    /**
     * Test that an exception is thrown if an archive cannot be extracted from the zip file.
     */
    public function testExceptionIsThrownIfArchiveCannotBeExtractedFromZip()
    {
        $model = factory(Pack::class)->make();

        $this->file->shouldReceive('isValid')->withNoArgs()->once()->andReturn(true);
        $this->file->shouldReceive('getMimeType')->withNoArgs()->twice()->andReturn('application/zip');

        $this->file->shouldReceive('getRealPath')->withNoArgs()->once()->andReturn('/test/real');
        $this->archive->shouldReceive('open')->once()->andReturn(true);
        $this->archive->shouldReceive('locateName')->twice()->andReturn(true);
        $this->archive->shouldReceive('getFromName')->once()->andReturn(self::JSON_FILE_CONTENTS);
        $this->creationService->shouldReceive('handle')->once()->andReturn($model);
        $this->archive->shouldReceive('extractTo')->once()->andReturn(false);

        try {
            $this->service->handle(1, $this->file);
        } catch (ZipExtractionException $exception) {
            $this->assertEquals(trans('admin/exceptions.packs.zip_extraction'), $exception->getMessage());
        }
    }

    /**
     * Provide valid JSON mimetypes to use in tests.
     *
     * @return array
     */
    public function jsonMimetypeProvider()
    {
        return [
            ['text/plain'],
            ['application/json'],
        ];
    }

    /**
     * Return invalid mimetypes for testing.
     *
     * @return array
     */
    public function invalidMimetypeProvider()
    {
        return [
            ['application/gzip'],
            ['application/x-gzip'],
            ['image/jpeg'],
        ];
    }

    /**
     * Return values for archive->locateName function, import.json and archive.tar.gz respectively.
     *
     * @return array
     */
    public function filenameProvider()
    {
        return [
            [true, false],
            [false, true],
            [false, false],
        ];
    }
}
